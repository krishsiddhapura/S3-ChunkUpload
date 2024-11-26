# S3 File Chunk Upload Demo

This repository demonstrates two methods for uploading large files to AWS S3 using chunked uploads. The demo is built with **Laravel** for backend and **AWS JavaScript SDK** for frontend functionality.

---

## Features

### 1. Presigned URL Method
- Generates a presigned URL on the server side using Laravel.
- The file chunks are uploaded to S3 directly via HTTP requests using the presigned URL.
- Suitable for smaller files or simple upload flows.

### 2. Multipart Upload Method
- Leverages the AWS JavaScript SDK to handle file chunking and upload in the browser.
- Efficient for uploading large files (multi-GB).
- Includes retry logic and a progress bar for tracking upload progress.

---

## Technologies Used
- **Backend:** Laravel (PHP framework)
- **Frontend:** HTML, JavaScript (with AWS SDK)
- **AWS Services:** S3 Bucket for file storage
