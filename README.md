# ImportUserCSV

## Installation Guide

1. **Download the Plugin**
   - Download the `ImportUserCSV` plugin as a ZIP archive or clone this repository to your local machine.

ImportUserCSV.zip
└── import-user-csv/
   └── init.php


2. **Upload to WordPress**
   - Go to ../wp-admin/plugin-install.php
   - Select **ImportUserCSV.zip** and after click to **Activate**.

3. **Import Users**
   - In the WordPress admin menu, go to **Users > Import Users CSV**.
   - Upload your CSV file and start the import process.

**CSV file requirements:**
- The first row must contain column headers.
- Use a semicolon (`;`) as the separator.

## Plugin Features

- **Bulk Import Users:** Import new users or update existing users from a CSV file in one click.
- **Automatic Avatar Handling:** Download and assign user avatars from URLs provided in the CSV.
- **Metadata Support:** Fill in all standard user fields (login, email, name, nickname, description, website, etc.) and popular social network links.
- **Transliteration:** Automatically transliterates Cyrillic names to Latin for logins and nicknames if needed.
- **Smart Update:** If a user already exists, only missing fields are updated—no data is overwritten unnecessarily.
- **Admin Interface:** Simple, user-friendly admin page for uploading CSV files and tracking import results.
- **Error Reporting:** Detailed error messages and import statistics are shown after each import.
- **No Language Dependencies:** All interface and messages are in English, no translation files required.

---

**Special thanks:** Avatar functionality is based on [Basic User Avatars](https://wordpress.org/plugins/basic-user-avatars/) by Jared Atchison. 
