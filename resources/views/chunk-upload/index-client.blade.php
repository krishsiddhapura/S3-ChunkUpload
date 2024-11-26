<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multipart Upload</title>
    <script src="https://sdk.amazonaws.com/js/aws-sdk-2.1407.0.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background: #f4f4f9;
        }
        h1 {
            text-align: center;
            color: #333;
        }
        #uploadContainer {
            max-width: 500px;
            margin: 50px auto;
            text-align: center;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        input[type="file"] {
            display: none;
        }
        label {
            display: inline-block;
            margin: 20px 0;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border-radius: 4px;
            cursor: pointer;
        }
        label:hover {
            background: #0056b3;
        }
        button {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        button:hover {
            background: #218838;
        }
        .progress-container {
            position: relative;
            width: 100%;
            height: 30px;
            background: #eee;
            border-radius: 15px;
            overflow: hidden;
            margin: 20px 0;
        }
        .progress-bar {
            position: absolute;
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, #00C0F9, #007bff, #0056b3);
            transition: width 0.4s ease;
        }
        .progress-text {
            position: absolute;
            width: 100%;
            height: 100%;
            text-align: center;
            line-height: 30px;
            color: #333;
            font-weight: bold;
        }
        #status {
            color: #333;
            margin-top: 10px;
        }
    </style>
</head>
<body>
<h1>Upload File to S3</h1>
<div id="uploadContainer">
    <label for="fileInput">Choose File</label>
    <input type="file" id="fileInput">
    <button id="uploadBtn">Upload</button>
    <div class="progress-container">
        <div class="progress-bar " id="progressBar"  style="width: 10%"></div>
        <div class="progress-text" id="progressText">10%</div>
    </div>
    <div id="status"></div>
</div>

<script>
    // AWS Configuration
    const accessKeyId = "{{env('AWS_ACCESS_KEY_ID')}}";
    const secretAccessKey = "{{env('AWS_SECRET_ACCESS_KEY')}}";
    const bucketName = "{{env('AWS_BUCKET')}}";
    const region = "{{env('AWS_DEFAULT_REGION')}}";

    // Configure AWS SDK with initial static credentials
    AWS.config.update({
        accessKeyId: accessKeyId,
        secretAccessKey: secretAccessKey,
        region: region,
        httpOptions: {
            timeout: 3600000, // 60 minutes
        },
        maxRetries: 3,
    });
    const s3 = new AWS.S3();

    // Initialize progress bar with a total file size
    function initializeProgressBar() {
        const progressBar = document.getElementById("progressBar");
        const progressText = document.getElementById("progressText");
        progressBar.style.width = "0%";
        progressText.textContent = "0%";
    }

    // Update progress bar with the cumulative uploaded size
    function updateProgress(uploadedBytes, totalBytes) {
        const progressBar = document.getElementById("progressBar");
        const progressText = document.getElementById("progressText");

        const percentage = Math.round((uploadedBytes / totalBytes) * 100);
        progressBar.style.width = percentage + "%";
        progressText.textContent = percentage + "%";
    }

    // Multipart upload logic
    async function multipartUploadParallel(file, fileName, chunkSizeMB, maxConcurrency = 10) {
        let uploadId;
        try {
            // Step 1: Create a multipart upload
            uploadId = await createMultipartUpload(file, fileName);

            // Step 2: Split file into chunks
            const chunkSize = chunkSizeMB * 1024 * 1024; // 50MB
            const chunks = Math.ceil(file.size / chunkSize);
            let uploadedBytes = 0;

            initializeProgressBar();

            // Step 3: Upload chunks with limited concurrency
            const uploadParts = [];
            const activeUploads = new Set(); // Set to track active uploads

            for (let i = 0; i < chunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(start + chunkSize, file.size);
                const part = file.slice(start, end);
                const partNumber = i + 1;

                // Start the part upload and track the promise
                const uploadPromise = uploadPartWithRetry(fileName, uploadId, part, partNumber)
                    .then(({ ETag, PartNumber }) => {
                        uploadedBytes += part.size;
                        updateProgress(uploadedBytes, file.size);
                        return { ETag, PartNumber };
                    })
                    .finally(() => activeUploads.delete(uploadPromise)); // Remove from active uploads after completion

                uploadParts.push(uploadPromise);
                activeUploads.add(uploadPromise);

                // Wait if the active uploads exceed maxConcurrency
                if (activeUploads.size >= maxConcurrency) {
                    await Promise.race(activeUploads);
                }
            }

            // Wait for all uploads to complete
            const completedParts = await Promise.all(uploadParts);

            // Step 4: Complete the multipart upload
            await completeMultipartUpload(fileName, uploadId, completedParts);

            document.getElementById("status").innerText = "Upload Complete!";
        } catch (error) {
            console.error("Upload failed:", error);
            document.getElementById("status").innerText = "Upload Failed!";
            if (uploadId) {
                await abortMultipartUpload(uploadId, fileName); // Abort if error occurs
            }
        }
    }

    // Create multipart upload
    async function createMultipartUpload(file,fileName) {
        const params = {
            Bucket: bucketName,
            Key: fileName,
            Expires: 3600, // Set expiration to 1 hour
        };

        const response = await s3.createMultipartUpload(params).promise();
        return response.UploadId;
    }

    // Upload part with automatic retry
    async function uploadPartWithRetry(key, uploadId, part, partNumber, retries = 3) {
        let attempt = 0;
        while (attempt < retries) {
            try {
                const params = {
                    Bucket: bucketName,
                    Key: key,
                    UploadId: uploadId,
                    PartNumber: partNumber,
                    Body: part,
                };

                const response = await s3.uploadPart(params).promise();
                return { ETag: response.ETag, PartNumber: partNumber };
            } catch (error) {
                attempt++;
                console.log(`Retry ${attempt}/${retries} failed for part ${partNumber}: ${error.message}`);
                if (attempt === retries) {
                    throw new Error(`Upload part ${partNumber} failed after ${retries} retries.`);
                }
                // Optionally, add a delay between retries if needed
                await new Promise(resolve => setTimeout(resolve, 2000)); // 2 second delay
            }
        }
    }

    // Complete multipart upload
    async function completeMultipartUpload(key, uploadId, parts) {
        const params = {
            Bucket: bucketName,
            Key: key,
            UploadId: uploadId,
            MultipartUpload: {
                Parts: parts.sort((a, b) => a.PartNumber - b.PartNumber),
            },
        };
        return await s3.completeMultipartUpload(params).promise();
    }

    // Abort upload and remove all files if error occurs
    async function abortMultipartUpload(uploadId, key) {
        const params = {
            Bucket: bucketName,
            Key: key,
            UploadId: uploadId,
        };

        try {
            await s3.abortMultipartUpload(params).promise();
            console.log("Multipart upload aborted and parts deleted.");
        } catch (error) {
            console.error("Failed to abort multipart upload:", error);
        }
    }

    // Event listener for the upload button
    document.getElementById("uploadBtn").addEventListener("click", () => {
        const fileInput = document.getElementById("fileInput");
        const file = fileInput.files[0];

        if (!file) {
            alert("Please select a file.");
            return;
        }

        multipartUploadParallel(file,'demo-file.mp4',5);
    });
</script>

</body>
</html>
