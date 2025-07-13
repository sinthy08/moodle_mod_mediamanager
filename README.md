# Moodle Media Manager 
[![Moodle Plugin](https://img.shields.io/badge/Moodle-Plugin-blue?style=for-the-badge&logo=moodle)](https://moodle.org)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-3.0)

**Moodle Media Manager** is a Moodle activity plugin that helps you offload uploaded files or resources to an Amazon S3 bucket and remove them from the local `moodledata` directory. The files are then served via public or pre-signed S3 URLs directly inside the activity.

---

## 📦 Features

- Uploads Moodle files to an Amazon S3 bucket.
- Deletes the uploaded file from `moodledata`.
- Embeds S3 file links in the activity view.
- Saves disk space on your Moodle server.
- Seamless integration with Moodle activity modules.

---

## ⚠️ Dependency

This plugin **requires** the [local_aws plugin](https://moodle.org/plugins/local_aws) to be installed and configured first.

### ➤ Install `local_aws`:

1. Download from [https://moodle.org/plugins/local_aws](https://moodle.org/plugins/local_aws)
2. Place it in `moodle/local/aws`
3. Go to **Site administration → Notifications** to complete the installation.
4. Configure AWS credentials and region in:
   ```
   Site administration → Plugins → Local plugins → AWS SDK
   ```

You must provide:
- AWS Access Key ID
- AWS Secret Access Key
- Default S3 Region

---

## 🚀 Installing Media Manager

Once `local_aws` is installed and configured:

1. Clone this repository into the `mod/` directory of your Moodle instance:
   ```bash
   git clone https://github.com/sinthy08/moodle_mod_mediamanager.git mediamanager
   ```

2. Log in to Moodle as an admin and finish the plugin installation steps.

3. Configure **Media Manager** settings in:
   ```
   Site administration → Plugins → Activity modules → Media Manager
   ```

You must set:
- Target S3 Bucket name
- (Optional) Folder path in S3 bucket

---

## 📚 Usage

1. Add a **Media Manager** activity to your course.
2. Upload a file or resource.
3. The file is automatically:
   - Uploaded to S3
   - Deleted from `moodledata`
   - Shown in the activity using the S3 URL

---

## 💡 Ideal For

- Moodle sites with limited disk space
- Large courses using multimedia resources
- Organizations already using AWS S3 for storage

---

## 🛡️ License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.html), same as Moodle core.

---

## 🤝 Contributing

Contributions and suggestions are welcome. Please open an issue or submit a pull request on GitHub.
