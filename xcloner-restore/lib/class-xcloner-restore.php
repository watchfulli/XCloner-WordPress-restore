<?php

namespace Watchful\XClonerRestore;

use Exception;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use mysqli;
use splitbrain\PHPArchive\ArchiveCorruptedException;
use splitbrain\PHPArchive\ArchiveIllegalCompressionException;
use splitbrain\PHPArchive\ArchiveIOException;
use splitbrain\PHPArchive\Tar;

class Xcloner_Restore
{
    const   MINIMUM_PHP_VERSION = '7.3.0';

    /** @var int */
    private $process_mysql_records_limit = 250;

    /** @var LocalFilesystemAdapter */
    private $adapter;
    /** @var Filesystem */
    private $filesystem;
    /** @var Logger */
    private $logger;
    /** @var string */
    private $root_dir;
    /** @var LocalFilesystemAdapter */
    private $target_adapter;
    /** @var Filesystem */
    private $target_filesystem;

    public function __construct()
    {
        register_shutdown_function([$this, 'exception_handler']);

        $this->root_dir = pathinfo(XCLONER_RESTORE_SCRIPT_PATH)['dirname'];

        $this->adapter = new LocalFilesystemAdapter($this->root_dir);
        $this->filesystem = new Filesystem($this->adapter, [
            'disable_asserts' => true,
        ]);

        $this->logger = new Logger('xcloner_restore');

        $logger_path = $this->get_logger_filename();

        if (!is_writeable($logger_path) && !touch($logger_path)) {
            $logger_path = 'php://stderr';
        }

        $this->logger->pushHandler(new StreamHandler($logger_path, Logger::DEBUG));

        if (isset($_POST['API_ID'])) {
            $this->logger->info('Processing ajax request ID ' . substr(filter_input(INPUT_POST, 'API_ID', FILTER_SANITIZE_STRING), 0, 15));
        }
    }

    public function exception_handler()
    {
        $error = error_get_last();

        if ($error['type'] && $this->logger) {
            $this->logger->info($this->friendly_error_type($error['type']) . ': ' . var_export($error, true));
        }
    }

    private function friendly_error_type($type)
    {
        static $levels = null;
        if ($levels === null) {
            $levels = [];
            foreach (get_defined_constants() as $key => $value) {
                if (strpos($key, 'E_') !== 0) {
                    continue;
                }
                $levels[$value] = $key;
            }
        }
        return ($levels[$type] ?? 'Error #{$type}');
    }

    public function get_logger_filename(): string
    {
        return $this->root_dir . DS . 'xcloner_restore.log';
    }

    /**
     * Init method
     *
     * @throws Exception
     */
    public function init(): void
    {
        if (isset($_POST['xcloner_action']) && $_POST['xcloner_action']) {
            $method = filter_input(INPUT_POST, 'xcloner_action', FILTER_SANITIZE_STRING);

            $method .= '_action';

            if (method_exists($this, $method)) {
                $this->logger->debug(sprintf('Starting action %s', $method));
                call_user_func([$this, $method]);
            } else {
                throw new Exception($method . ' does not exists');
            }
        }

        $this->check_system();
    }

    /**
     * Write file method
     *
     * @throws Exception
     */
    public function write_file_action(): int
    {
        if (!isset($_POST['file'])) {
            throw new Exception('File not set');
        }
        $target_file = pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME). DS . filter_input(INPUT_POST, 'file', FILTER_SANITIZE_STRING);

        if (!$_POST['start']) {
            $fp = fopen($target_file, 'wb+');
        } else {
            $fp = fopen($target_file, 'ab+');
        }

        if (!$fp) {
            throw new Exception('Unable to open $target_file file for writing');
        }

        fseek($fp, $_POST['start']);

        if (isset($_FILES['blob'])) {
            $this->logger->debug(sprintf('Writing %s bytes to file %s starting position %s using FILES blob', filesize($_FILES['blob']['tmp_name']), $target_file, $_POST['start']));

            $blob = file_get_contents($_FILES['blob']['tmp_name']);

            if (!$bytes_written = fwrite($fp, $blob)) {
                throw new Exception('Unable to write data to file $target_file');
            }

            try {
                unlink($_FILES['blob']['tmp_name']);
            } catch (Exception $e) {
                //silent message
            }
        } elseif (isset($_POST['blob'])) {
            $this->logger->debug(sprintf('Writing %s bytes to file %s starting position %s using POST blob', strlen($_POST['blob']), $target_file, $_POST['start']));

            $blob = $_POST['blob'];

            if (!$bytes_written = fwrite($fp, $blob)) {
                throw new Exception('Unable to write data to file $target_file');
            }
        } else {
            throw new Exception('Upload failed, did not receive any binary data');
        }

        fclose($fp);

        return $bytes_written;
    }

    /**
     * Connect to mysql server method
     *
     * @throws Exception
     */
    public function mysql_connect(
        string $remote_mysql_host,
        string $remote_mysql_user,
        string $remote_mysql_pass,
        string $remote_mysql_db
    ): mysqli
    {
        $this->logger->info(sprintf('Connecting to mysql database %s with %s@%s', $remote_mysql_db, $remote_mysql_user, $remote_mysql_host));

        $mysqli = new mysqli($remote_mysql_host, $remote_mysql_user, $remote_mysql_pass, $remote_mysql_db);

        if ($mysqli->connect_error) {
            throw new Exception('Connect Error (' . $mysqli->connect_errno . ') '
                . $mysqli->connect_error);
        }

        $mysqli->query("SET sql_mode='';");
        $mysqli->query("SET foreign_key_checks = 0;");
        if (isset($_REQUEST['charset_of_file']) && $_REQUEST['charset_of_file']) {
            $mysqli->query('SET NAMES ' . $_REQUEST['charset_of_file']);
        } else {
            $mysqli->query('SET NAMES utf8;');
        }

        return $mysqli;
    }

    /**
     * Restore mysql backup file
     *
     * @throws Exception
     */
    public function restore_mysql_backup_action()
    {
        $mysqldump_file = filter_input(INPUT_POST, 'mysqldump_file', FILTER_SANITIZE_STRING);
        $remote_path = filter_input(INPUT_POST, 'remote_path', FILTER_SANITIZE_STRING);
        $remote_mysql_user = filter_input(INPUT_POST, 'remote_mysql_user', FILTER_SANITIZE_STRING);
        $remote_mysql_pass = filter_input(INPUT_POST, 'remote_mysql_pass', FILTER_SANITIZE_STRING);
        $remote_mysql_db = filter_input(INPUT_POST, 'remote_mysql_db', FILTER_SANITIZE_STRING);
        $remote_mysql_host = filter_input(INPUT_POST, 'remote_mysql_host', FILTER_SANITIZE_STRING);
        $execute_query = trim(stripslashes($_POST['query']));
        $start = filter_input(INPUT_POST, 'start', FILTER_SANITIZE_NUMBER_INT);

        $wp_home_url = filter_input(INPUT_POST, 'wp_home_url', FILTER_SANITIZE_STRING);
        $remote_restore_url = filter_input(INPUT_POST, 'remote_restore_url', FILTER_SANITIZE_STRING);

        $wp_site_url = filter_input(INPUT_POST, 'wp_site_url', FILTER_SANITIZE_STRING);
        $restore_site_url = filter_input(INPUT_POST, 'restore_site_url', FILTER_SANITIZE_STRING);

        $mysql_backup_file = $remote_path . DS . $mysqldump_file;

        if (!file_exists($mysql_backup_file)) {
            throw new Exception(sprintf('Mysql backup file %s does not exists', $mysql_backup_file));
        }

        $mysqli = $this->mysql_connect($remote_mysql_host, $remote_mysql_user, $remote_mysql_pass, $remote_mysql_db);

        $line_count = 0;
        $query = '';
        $return = [];
        $return['finished'] = 1;
        $return['backup_file'] = $mysqldump_file;
        $return['backup_size'] = filesize($mysql_backup_file);

        $fp = fopen($mysql_backup_file, 'r');
        if ($fp) {
            $this->logger->info(sprintf('Opening mysql dump file %s at position %s.', $mysql_backup_file, $start));
            fseek($fp, $start);
            while ($line_count <= $this->process_mysql_records_limit && ($line = fgets($fp)) !== false) {
                if (substr($line, 0, 1) == '#') {
                    continue;
                }

                //check if line is empty
                if ($line == "\n" or trim($line) == '') {
                    continue;
                }

                $query .= $line;
                if (substr($line, strlen($line) - 2, strlen($line)) != ";\n") {
                    continue;
                }

                if ($execute_query) {
                    $query = (($execute_query));
                    $execute_query = '';
                }

                if ($wp_site_url && $wp_home_url && strlen($wp_home_url) < strlen($wp_site_url)) {
                    list($wp_home_url, $wp_site_url) = [$wp_site_url, $wp_home_url];
                    list($remote_restore_url, $restore_site_url) = [$restore_site_url, $remote_restore_url];
                }

                if ($wp_home_url && $remote_restore_url && strpos($query, $wp_home_url) !== false) {
                    $query = $this->url_replace($wp_home_url, $remote_restore_url, $query);
                }

                if ($wp_site_url && $restore_site_url && strpos($query, $wp_site_url) !== false) {
                    $query = $this->url_replace($wp_site_url, $restore_site_url, $query);
                }

                if (!$mysqli->query($query) && !stristr($mysqli->error, 'Duplicate entry')) {
                    $return['start'] = ftell($fp) - strlen($line);
                    $return['query_error'] = true;
                    $return['query'] = $query;
                    $return['message'] = sprintf('Mysql Error: %s\n', $mysqli->error);

                    $this->logger->error($return['message']);

                    $this->send_response(418, $return);
                }

                $query = '';

                $line_count++;
            }

            $return['start'] = ftell($fp);

            $this->logger->info(sprintf('Executed %s queries of size %s bytes', $line_count, ($return['start'] - $start)));

            if (!feof($fp)) {
                $return['finished'] = 0;
            } else {
                $this->logger->info('Mysql Import Done.');
            }

            fclose($fp);
        }

        $this->send_response(200, $return);
    }

    /**
     * Url replace method inside database backup file
     */
    private function url_replace(
        string $search,
        string $replace,
        string $query
    )
    {
        $this->logger->info(sprintf('Doing url replace on query with length %s', strlen($query)), ['QUERY_REPLACE']);
        $query = str_replace($search, $replace, $query);
        $original_query = $query;

        if ($this->has_serialized($query)) {
            $this->logger->info('Query contains serialized data, doing serialized size fix', ['QUERY_REPLACE']);
            $query = $this->do_serialized_fix($query);

            if (!$query) {
                $this->logger->info('Serialization probably failed here...', ['QUERY_REPLACE']);
                $query = $original_query;
            }
        }
        $this->logger->info(sprintf('New query length is %s', strlen($query)), ['QUERY_REPLACE']);

        return $query;
    }

    /**
     * List backup files method
     *
     * @throws FilesystemException
     */
    public function list_backup_files_action()
    {
        $backup_parts = [];

        $source_backup_file = filter_input(INPUT_POST, 'file', FILTER_SANITIZE_STRING);
        $return['part'] = (int)filter_input(INPUT_POST, 'part', FILTER_SANITIZE_STRING);

        $backup_file = $source_backup_file;

        if ($this->is_multipart($backup_file)) {
            $backup_parts = $this->get_multipart_files($backup_file);
            $backup_file = $backup_parts[$return['part']];
        }

        if ($this->is_encrypted_file($backup_file)) {
            $return['error'] = true;
            $return['message'] = "Backup archive is encrypted, please decrypt it first before you can list it's content.";
            $this->send_response(200, $return);
        }

        try {
            $tar = new Tar();
            $tar->open($this->root_dir . DS . $backup_file);

            $data = $tar->contents();
        } catch (Exception $e) {
            $return['error'] = true;
            $return['message'] = $e->getMessage();
            $this->send_response(200, $return);
        }

        $return['files'] = [];
        $return['finished'] = 1;
        $return['total_size'] = filesize($this->root_dir . DS . $backup_file);
        $i = 0;

        if (isset($data['extracted_files']) && is_array($data['extracted_files'])) {
            foreach ($data['extracted_files'] as $file) {
                $return['files'][$i]['path'] = $file->getPath();
                $return['files'][$i]['size'] = $file->getSize();
                $return['files'][$i]['mtime'] = date('d M,Y H:i', $file->getMtime());

                $i++;
            }
        }

        if (isset($data['start'])) {
            $return['start'] = $data['start'];
            $return['finished'] = 0;
        } else {
            if ($this->is_multipart($source_backup_file)) {
                $return['start'] = 0;

                ++$return['part'];

                if ($return['part'] < sizeof($backup_parts)) {
                    $return['finished'] = 0;
                }
            }
        }

        $this->send_response(200, $return);
    }

    /**
     * Finish backup restore method
     *
     * @throws Exception
     * @throws FilesystemException
     */
    public function restore_finish_action()
    {
        $remote_path = filter_input(INPUT_POST, 'remote_path', FILTER_SANITIZE_STRING);
        $backup_archive = filter_input(INPUT_POST, 'backup_archive', FILTER_SANITIZE_STRING);

        $wp_home_url = filter_input(INPUT_POST, 'wp_home_url', FILTER_SANITIZE_STRING);
        $remote_restore_url = filter_input(INPUT_POST, 'remote_restore_url', FILTER_SANITIZE_STRING);

        $remote_mysql_user = filter_input(INPUT_POST, 'remote_mysql_user', FILTER_SANITIZE_STRING);
        $remote_mysql_pass = filter_input(INPUT_POST, 'remote_mysql_pass', FILTER_SANITIZE_STRING);
        $remote_mysql_db = filter_input(INPUT_POST, 'remote_mysql_db', FILTER_SANITIZE_STRING);
        $remote_mysql_host = filter_input(INPUT_POST, 'remote_mysql_host', FILTER_SANITIZE_STRING);

        $update_remote_site_url = filter_input(INPUT_POST, 'update_remote_site_url', FILTER_SANITIZE_NUMBER_INT);
        $delete_restore_script = filter_input(INPUT_POST, 'delete_restore_script', FILTER_SANITIZE_NUMBER_INT);
        $delete_backup_archive = filter_input(INPUT_POST, 'delete_backup_archive', FILTER_SANITIZE_NUMBER_INT);
        $delete_backup_temporary_folder = filter_input(INPUT_POST, 'delete_backup_temporary_folder', FILTER_SANITIZE_NUMBER_INT);

        if ($update_remote_site_url) {
            $mysqli = $this->mysql_connect($remote_mysql_host, $remote_mysql_user, $remote_mysql_pass, $remote_mysql_db);
            $this->update_wp_config($remote_path, $remote_mysql_host, $remote_mysql_user, $remote_mysql_pass, $remote_mysql_db);
            $this->update_wp_url($remote_path, $remote_restore_url, $mysqli);
        }

        if ($delete_backup_temporary_folder) {
            $this->delete_backup_temporary_folder($remote_path);
        }

        if ($delete_backup_archive) {
            $this->filesystem->deleteDirectory(pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME));
        }

        if ($delete_restore_script) {
            $this->delete_self();
        }

        $return = 'Restore Process Finished.<br>';

        $return .= $delete_restore_script ? '<i>Please double check that the restore script has been deleted from the server.</i>' : '<b>Please be sure to delete the restore script from the server.</b';

        $this->send_response(200, $return);
    }

    /**
     * Delete backup temporary folder
     * @throws FilesystemException
     */
    private function delete_backup_temporary_folder($remote_path): void
    {
        $this->target_adapter = new LocalFilesystemAdapter($remote_path);
        $this->target_filesystem = new Filesystem($this->target_adapter, ['disable_asserts' => true]);

        $list = $this->target_filesystem->listContents('./');

        /** @var $file DirectoryAttributes */
        foreach ($list as $file) {
            if (
                !$file->isDir() ||
                !preg_match("/xcloner-(\w*)/", $file->path()) ||
                $file->path() === 'xcloner-restore'
            ) {
                continue;
            }

            $this->logger->info(sprintf('Deleting temporary folder %s', $file['path']));
            $this->target_filesystem->deleteDirectory($file['path']);
        }
    }

    /**
     * Delete restore script method
     */
    private function delete_self()
    {
        try {
            $this->filesystem->delete(pathinfo(XCLONER_RESTORE_SCRIPT_PATH, PATHINFO_BASENAME));
            $this->filesystem->deleteDirectory(pathinfo(XCLONER_RESTORE_LIB_PATH, PATHINFO_BASENAME));
            $this->filesystem->delete(pathinfo($this->get_logger_filename(), PATHINFO_BASENAME));
            $this->target_filesystem->deleteDirectory('xcloner-restore');
        } catch (FilesystemException $e) {
            $this->logger->error($e->getMessage());
        }
    }

    /**
     * Update WordPress url in wp-config.php method
     *
     * @throws Exception
     */
    private function update_wp_url($wp_path, $url, $mysqli): void
    {
        $wp_config = $wp_path . DS . 'wp-config.php';

        $this->logger->info(sprintf('Updating site url to %s', $url));

        if (file_exists($wp_config)) {
            $config = file_get_contents($wp_config);
            preg_match("/.*table_prefix.*=.*'(.*)'/i", $config, $matches);
            if (isset($matches[1])) {
                $table_prefix = $matches[1];
            } else {
                throw new Exception('Could not load wordpress table prefix from wp-config.php file.');
            }
        } else {
            throw new Exception('Could not update the SITEURL and HOME, wp-config.php file not found');
        }

        if (!$mysqli->query('update ' . $table_prefix . "options set option_value='" . ($url) . "' where option_name='home'")) {
            throw new Exception(sprintf("Could not update the HOME option, error: %s\n", $mysqli->error));
        }

        if (!$mysqli->query('update ' . $table_prefix . "options set option_value='" . ($url) . "' where option_name='siteurl'")) {
            throw new Exception(sprintf("Could not update the SITEURL option, error: %s\n", $mysqli->error));
        }

    }

    /**
     * Update local wp-config.php file method
     *
     * @throws Exception
     */
    private function update_wp_config(
        string $remote_path,
        string $remote_mysql_host,
        string $remote_mysql_user,
        string $remote_mysql_pass,
        string $remote_mysql_db
    ): void
    {
        $wp_config = $remote_path . DS . 'wp-config.php';

        if (!file_exists($wp_config)) {
            throw new Exception('Could not find the wp-config.php in ' . $remote_path);
        }

        $content = file_get_contents($wp_config);

        $content = preg_replace("/(?<=DB_NAME', ')(.*?)(?='\);)/", $remote_mysql_db, $content);
        $content = preg_replace("/(?<=DB_USER', ')(.*?)(?='\);)/", $remote_mysql_user, $content);
        $content = preg_replace("/(?<=DB_PASSWORD', ')(.*?)(?='\);)/", $remote_mysql_pass, $content);
        $content = preg_replace("/(?<=DB_HOST', ')(.*?)(?='\);)/", $remote_mysql_host, $content);

        $file_perms = fileperms($wp_config);

        chmod($wp_config, 0777);

        $this->logger->info('Updating wp-config.php file with the new mysql details');

        if (!file_put_contents($wp_config, $content)) {
            throw new Exception('Could not write updated config data to ' . $wp_config);
        }

        chmod($wp_config, $file_perms);

    }

    /**
     * List mysqldump database backup files
     *
     * @throws FilesystemException
     */
    public function list_mysqldump_backups_action()
    {
        $source_backup_file = filter_input(INPUT_POST, 'backup_file', FILTER_SANITIZE_STRING);
        $remote_path = filter_input(INPUT_POST, 'remote_path', FILTER_SANITIZE_STRING);

        $hash = $this->get_hash_from_backup($source_backup_file);

        $this->target_adapter = new LocalFilesystemAdapter($remote_path);
        $this->target_filesystem = new Filesystem($this->target_adapter, [
            'disable_asserts' => true,
        ]);

        $mysqldump_list = [];
        $list = $this->target_filesystem->listContents('./');

        foreach ($list as $content) {
            $matches = [];

            if ($content['type'] !== 'dir' || !preg_match("/xcloner-(\w*)/", $content['path'], $matches)) {
                continue;
            }

            $files = $this->target_filesystem->listContents($content['path']);
            /** @var  $file */
            foreach ($files as $file) {
                if (pathinfo($file->path(), PATHINFO_EXTENSION) !== 'sql') {
                    continue;
                }
                $this->logger->info(sprintf('Found %s mysql backup file', $file['path']));
                $mysqldump_list[$file['path']]['path'] = $file['path'];
                $mysqldump_list[$file['path']]['size'] = $file['file_size'];
                $mysqldump_list[$file['path']]['timestamp'] = date('Y-m-d H:i:s', $file['lastModified']);

                if ($hash && $hash == $matches[1]) {
                    $mysqldump_list[$file['path']]['selected'] = 'selected';
                } else {
                    $mysqldump_list[$file['path']]['selected'] = '';
                }
            }
        }

        $this->sort_by($mysqldump_list, 'timestamp', 'desc');
        $return['files'] = $mysqldump_list;

        $this->send_response(200, $return);
    }

    /**
     * Get backup hash method
     *
     * @param $backup_file
     * @return false|string
     */
    private function get_hash_from_backup($backup_file)
    {
        if (!$backup_file) {
            return false;
        }

        $result = preg_match("/-(\w*)./", substr($backup_file, strlen($backup_file) - 10, strlen($backup_file)), $matches);

        if ($result && isset($matches[1])) {
            return ($matches[1]);
        }

        return false;
    }

    /**
     * List backup archives found on local system
     *
     * @throws FilesystemException
     */
    public function list_backup_archives_action()
    {
        $list = $this->filesystem->listContents(pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME))->toArray();

        $list = array_map(static function (FileAttributes $item) {
            $item = $item->jsonSerialize();
            $item['path'] = pathinfo($item['path'], PATHINFO_BASENAME);
            return $item;
        }, $list);

        $this->sort_by($list, 'lastModified', 'desc');

        $return['files'] = $list;

        $this->send_response(200, $return);
    }

    /**
     * Restore backup archive to local path
     *
     * @throws FilesystemException
     * @throws ArchiveCorruptedException
     * @throws ArchiveIOException
     * @throws ArchiveIllegalCompressionException
     */
    public function restore_backup_to_path_action()
    {
        $source_backup_file = filter_input(INPUT_POST, 'backup_file', FILTER_SANITIZE_STRING);
        $remote_path = filter_input(INPUT_POST, 'remote_path', FILTER_SANITIZE_STRING);
        $include_filter_files = filter_input(INPUT_POST, 'filter_files', FILTER_SANITIZE_STRING);
        $exclude_filter_files = '';
        $start = filter_input(INPUT_POST, 'start', FILTER_SANITIZE_NUMBER_INT);
        $return['part'] = (int)filter_input(INPUT_POST, 'part', FILTER_SANITIZE_NUMBER_INT);
        $return['processed'] = (int)filter_input(INPUT_POST, 'processed', FILTER_SANITIZE_NUMBER_INT);

        $this->target_adapter = new LocalFilesystemAdapter($remote_path);
        $this->target_filesystem = new Filesystem($this->target_adapter, ['disable_asserts' => true]);

        $backup_file = pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME) . DS . $source_backup_file;

        $return['finished'] = 1;
        $return['extracted_files'] = [];
        $return['total_size'] = $this->get_backup_size($source_backup_file);

        $backup_archive = new Tar();
        if ($this->is_multipart($source_backup_file)) {
            if (!$return['part']) {
                $return['processed'] += $this->target_filesystem->fileSize($source_backup_file);
            }

            $backup_parts = $this->get_multipart_files($source_backup_file);
            $backup_file = $backup_parts[$return['part']];
        }

        if ($this->is_encrypted_file($source_backup_file)) {
            $message = sprintf('Backup file %s seems encrypted, please Decrypt it first from your Manage Backups panel.', $backup_file);
            $this->logger->error($message);
            $this->send_response(500, $message);
            return;
        }

        $this->logger->info(sprintf('Opening backup archive %s at position %s', $backup_file, $start));
        $backup_archive->open($backup_file);

        $extracted_files = $backup_archive->extract($remote_path, '', $exclude_filter_files, $include_filter_files);

        foreach ($extracted_files as $file_info) {
            $this->logger->info(sprintf('Extracted %s file', $file_info->getPath()));
            $return['extracted_files'][] = $file_info->getPath() . ' (' . $file_info->getSize() . ' bytes)';
        }

        if (isset($extracted_files['start'])) {
            $return['finished'] = 0;
            $return['start'] = $extracted_files['start'];
        } else {
            $return['processed'] += $start;

            if ($this->is_multipart($source_backup_file)) {
                $return['start'] = 0;

                ++$return['part'];

                if ($return['part'] < sizeof($backup_parts)) {
                    $return['finished'] = 0;
                }
            }
        }

        if ($return['finished']) {
            $this->logger->info(sprintf('Done extracting %s', $source_backup_file));
        }

        $return['backup_file'] = $source_backup_file;

        $this->send_response(200, $return);
    }

    /**
     * Check if provided filename has encrypted suffix
     *
     * @throws FilesystemException
     */
    public function is_encrypted_file($filename): bool
    {
        $fp = $this->filesystem->readStream(pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME) . DS . $filename);
        if (is_resource($fp)) {
            $encryption_length = fread($fp, 16);
            fclose($fp);
            if (is_numeric($encryption_length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current directory method
     */
    public function get_current_directory_action()
    {
        $restore_script_url = filter_input(INPUT_POST, 'restore_script_url', FILTER_SANITIZE_STRING);

        $pathinfo = pathinfo(XCLONER_RESTORE_SCRIPT_PATH);

        $return['remote_mysql_host'] = 'localhost';
        $return['remote_mysql_user'] = '';
        $return['remote_mysql_pass'] = '';
        $return['remote_mysql_db'] = '';

        $return['dir'] = ($pathinfo['dirname']);
        if (substr($return['dir'], -strlen('xcloner-restore')) === 'xcloner-restore') {
            $return['dir'] = substr($return['dir'], 0, -strlen('xcloner-restore'));
        }

        $return['restore_script_url'] = str_replace($pathinfo['basename'], '', $restore_script_url);
        if (substr($return['restore_script_url'], -strlen('xcloner-restore/')) === 'xcloner-restore/') {
            $return['restore_script_url'] = substr($return['restore_script_url'], 0, -strlen('xcloner-restore/'));
        }

        $return['restore_script_url'] = rtrim($return['restore_script_url'], '/');

        $this->logger->info(sprintf('Determining current url as %s and path as %s', $return['dir'], $return['restore_script_url']));

        $this->send_response(200, $return);
    }

    /**
     * Check current filesystem
     *
     * @throws Exception
     */
    public function check_system()
    {
        $tmp_file = md5(time());
        if (!file_put_contents($tmp_file, '++')) {
            throw new Exception('Could not write to new host');
        }

        if (!unlink($tmp_file)) {
            throw new Exception('Could not delete temporary file from new host');
        }

        $max_upload = $this->return_bytes((ini_get('upload_max_filesize')));
        $max_post = $this->return_bytes((ini_get('post_max_size')));

        $return['max_upload_size'] = min($max_upload, $max_post);
        $return['status'] = true;

        $this->logger->info(sprintf('Current filesystem max upload size is %s bytes', $return['max_upload_size']));

        $this->send_response(200, $return);
    }

    /**
     * Return bytes from human-readable value
     *
     */
    private function return_bytes($val): int
    {
        $numeric_val = (int)trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        switch ($last) {
            // The 'G' modifier is available since PHP 5.1.0
            case 'g':
                //gigabytes
                $numeric_val *= 1024;
            // no break
            case 'm':
                //megabytes
                $numeric_val *= 1024;
            // no break
            case 'k':
                //kilobytes
                $numeric_val *= 1024;
        }

        return $numeric_val;
    }

    /**
     * Check if backup archive os multipart
     *
     */
    public function is_multipart(string $backup_name): bool
    {
        if (stristr($backup_name, '-multipart')) {
            return true;
        }

        return false;
    }

    /**
     * Get backup archive size
     *
     * @throws FilesystemException
     */
    public function get_backup_size(string $backup_name): int
    {
        $backup_size = $this->filesystem->fileSize(pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME) . DS . $backup_name);
        if ($this->is_multipart($backup_name)) {
            $backup_parts = $this->get_multipart_files($backup_name);
            foreach ($backup_parts as $part_file) {
                $backup_size += $this->filesystem->fileSize($part_file);
            }
        }

        return $backup_size;
    }

    /**
     * Get multipart backup files list
     *
     * @throws FilesystemException
     */
    public function get_multipart_files(string $backup_name)
    {
        $files = [];

        if ($this->is_multipart($backup_name)) {
            $lines = explode(PHP_EOL, $this->filesystem->read(pathinfo(XCLONER_RESTORE_ARCHIVE_PATH, PATHINFO_BASENAME) . DS . $backup_name));
            foreach ($lines as $line) {
                if ($line) {
                    $data = str_getcsv($line);
                    $files[] = $data[0];
                }
            }
        }

        return $files;
    }

    /**
     * Sort_by method
     */
    private function sort_by(&$array, $field, $direction = 'asc'): bool
    {
        $direction = strtolower($direction);

        usort(
            $array,

            /**
             * @param string $b
             */
            function ($a, $b) use ($field, $direction) {
                $a = $a[$field];
                $b = $b[$field];

                if ($a == $b) {
                    return 0;
                }

                if ($direction == 'desc') {
                    if ($a > $b) {
                        return -1;
                    } else {
                        return 1;
                    }
                } else {
                    if ($a < $b) {
                        return -1;
                    } else {
                        return 1;
                    }
                }

                //return ($a.($direction == 'desc' ? '>' : '<').$b) ? -1 : 1;
            }
        );

        return true;
    }

    /**
     * Send response method
     *
     */
    public static function send_response(int $status, $response)
    {
        header('HTTP/1.1 200');
        header('Content-Type: application/json');
        $return['status'] = $status;
        $return['statusText'] = $response;

        if (isset($response['error']) && $response['error']) {
            $return['statusText'] = $response['message'];
            $return['error'] = true;
        } elseif ($status != 200 && $status != 418) {
            $return['error'] = true;
            $return['message'] = $response;
        }

        die(json_encode($return));
    }

    /**
     * Serialize fix methods below for mysql query lines
     */
    public function do_serialized_fix($query)
    {
        $query = str_replace(["\\n", "\\r", "\\'"], ["", "", "\""], ($query));

        return preg_replace_callback('!s:(\d+):([\\\\]?"[\\\\]?"|[\\\\]?"((.*?)[^\\\\])[\\\\]?");!', function ($m) {
            if (!isset($m[3])) {
                $m[3] = '';
            }

            return 's:' . strlen(($m[3])) . ':\"' . ($m[3]) . '\";';
        }, $query);
    }

    /**
     * Unescape quotes method
     */
    private function unescape_quotes($value)
    {
        return str_replace('\"', '"', $value);
    }

    /**
     * Unescape mysql method
     *
     * @param $value
     * @return mixed
     */
    private function unescape_mysql($value)
    {
        return str_replace(
            ["\\\\", "\\0", "\\n", "\\r", "\Z", "\'", '\"'],
            ["\\", "\0", "\n", "\r", "\x1a", "'", '"'],
            $value
        );
    }

    /**
     * Check if string is in serialized format
     *
     * @param $s
     * @return bool
     */
    private function has_serialized($s): bool
    {
        if (
            stristr($s, '{') !== false &&
            stristr($s, '}') !== false &&
            stristr($s, ';') !== false &&
            stristr($s, ':') !== false
        ) {
            return true;
        } else {
            return false;
        }
    }
}
