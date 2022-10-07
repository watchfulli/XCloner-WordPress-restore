---
name: Restore testing
about: Test the restore script before releasing a new version,
title: "[TESTING] test restore script x.y.z"
labels: testing
assignees: jimiero

---

# Target site details & prep
1. Site name: Enter name of the target testing site.
1. DB Prefix: Enter name of database prefix for this site.
- [ ] Download testing version of restore script.
- [ ] Extract testing script.
- [ ] Upload testing script (including the `vendor` folder) to root of target site.

# Source site details & prep
1. Site name: Enter name of the source testing site.
1. DB Prefix: Enter name of database prefix for this site.
- [ ] Install most recent version of XCloner (or testing version as appropriate).
- [ ] Create a full site backup.
  - [ ] If database is used for multiple sites, be sure to only select the tables for the site you are backing up
  - Backup size: Enter size of backup in MB.
  
# Testing the restore process
- [ ] Visit the restore area of XCloner on the source site. 
- [ ] Enter the URL of the restore script and click `Check Connection`.
  - [ ] Connection is good.
- [ ] Select the backup created on the source site from the dropdown and click `Upload`.
  - [ ] Upload completes.
- [ ] Click `Next` to restore the files.
  - [ ] File restoration completes.
- [ ] Click `Next` to view the database details.
  - [ ] Enter the database details.
- [ ] Click `Next` to restore the database.
- [ ] Confirmation & Cleanup
  - [ ] Target site
    - [ ] Target site loads cloned website.
    - [ ] Upload script deleted from target site.
    - [ ] Backup archive deleted from target site.
    - [ ] Extracted archive folder deleted from target site.
