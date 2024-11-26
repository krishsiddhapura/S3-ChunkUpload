<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>File Upload Example</title>
</head>

<body>

    <input type="file" id="fileInput">
    <button id="uploadButton">Upload</button>

    <script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
    <script>
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
            }
        });
        $(document).ready(function() {
            // Handle file change event
            $('#fileInput').change(function() {
                var file = this.files[0];
                uploadFile(file);
            });

            function uploadFile(file) {
                var chunkSize = 5 * 1024 * 1024; // 5MB chunk size
                var offset = 0;
                let uploadId = "";
                let newfileName = "";
                let partUrls = [];
                let fileSize = file.size;
                let fileName = file.name;
                let etags = [];
                let chunkNumber = 1;
                let presignUrlIndex = 0;
                $.ajax({
                    url: "{{ route('presign-urls') }}",
                    type: 'post',
                    data: {
                        filesize: fileSize,
                        filename: fileName
                    },
                    success: function(response) {

                        uploadId = response.upload_id;
                        newfileName = response.file_name;
                        partUrls = response.part_urls;

                        // Start reading the first chunk
                        readChunk();
                    },
                    error: function(error) {
                        console.log("upload btn error");
                        console.log(error);
                    },
                });


                function readChunk() {
                    var reader = new FileReader();
                    var blob = file.slice(offset, offset + chunkSize);

                    reader.onload = function(e) {
                        var chunkData = e.target.result;

                        // Send the chunkData to the server using AJAX
                        presignurl = partUrls[presignUrlIndex];
                        sendChunkToServer(chunkData, chunkNumber, presignurl, partUrls.length);

                        offset += chunkSize;
                        if (offset < file.size) {
                            chunkNumber++;
                            presignUrlIndex++;
                            // Continue reading the next chunk
                            readChunk();
                        }
                    };

                    reader.readAsArrayBuffer(blob);
                }

                function sendChunkToServer(chunkData, chunkNumber, presignurl, partUrlsCount) {
                    $.ajax({
                        url: presignurl,
                        type: 'PUT',
                        data: chunkData,
                        processData: false,
                        contentType: false,
                        success: function(dataResult, status, xhr) {
                            var etag = xhr.getResponseHeader('Etag') || "";
                            etag = etag.replace(/^"(.*)"$/, '$1');
                            var partNumber = presignurl.match(/[?&]partNumber=([^&]+)/)[1] ?? 0;
                            etags.push({
                                PartNumber: parseInt(partNumber),
                                ETag: etag
                            });
                            if (etags.length == partUrlsCount) {
                                mergeUrl();
                            }
                        },
                        error: function(error) {
                            console.error('Error uploading video:', error);
                        }
                    });
                }

                function mergeUrl() {
                    $.ajax({
                        url: "{{ route('merge-url-frontend') }}",
                        type: 'post',
                        data: {
                            upload_id: uploadId,
                            etags: etags,
                            newFileName: newfileName,
                        },
                        success: function(response) {
                            console.log("mergeUrl success");
                            console.log(response);
                            window.open(response.object_url, '_blank');
                        },
                        error: function(error) {
                            console.log("mergeUrl error");
                            console.log(error);
                        }
                    });
                }
            }
        });
    </script>
</body>

</html>
