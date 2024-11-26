<?php

namespace App\Http\Controllers;

use Aws\S3\S3Client;
use Illuminate\Http\Request;

class ChunksUploadController extends Controller
{
    public function index(){
        return view('chunk-upload.index');
    }

    public function indexClient(){
        return view('chunk-upload.index-client');
    }

    public function presignUrls(Request $request){
        $filesize = $request->input('filesize');
        $fileName = $request->input('filename');
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $chunkSize = 1024 * 1024 * 5;
        $totalChunks = (int)ceil($filesize/$chunkSize);
        $fileNameGenrate = rand(0000,9999).'_'. time().'.'.$extension;

        // Replace these with your AWS credentials
        $awsAccessKeyId = env('AWS_ACCESS_KEY_ID');
        $awsSecretAccessKey = env('AWS_SECRET_ACCESS_KEY');
        $region = env('AWS_DEFAULT_REGION');
        $bucketName = env('AWS_BUCKET');
        $objectKey = 'video/'.$fileNameGenrate; // file name

        // Create an S3 client
        $s3Client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $awsAccessKeyId,
                'secret' => $awsSecretAccessKey,
            ],
        ]);

        // Initiate a multipart upload
        $result = $s3Client->createMultipartUpload([
            'Bucket' => $bucketName,
            'Key' => $objectKey,
        ]);

        $uploadId = $result['UploadId'];

        // Generate pre-signed URLs for each part
        $partUrls = [];
        for ($partNumber = 1; $partNumber <= $totalChunks; $partNumber++) {
            $presignedUrl = $s3Client->createPresignedRequest(
                $s3Client->getCommand('UploadPart', [
                    'Bucket' => $bucketName,
                    'Key' => $objectKey,
                    'PartNumber' => $partNumber,
                    'UploadId' => $uploadId,
                ]),
                '+240 minutes'
            );
            $partUrls[] = (string) $presignedUrl->getUri();
        }
        return response()->json(['status'=>true,'upload_id' => $uploadId,'part_urls' => $partUrls,'file_name'=>$fileNameGenrate]);
    }

    public function mergeUrlFrontend(Request $request){
        $uploadId = $request['upload_id'];
        $objectKey = 'abc/'.$request['newFileName'];
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);

        $completedParts = array_map(function($etags){
            return [
                'PartNumber' => (int)$etags['PartNumber'],
                'ETag' => $etags['ETag'],
            ];
        },$request['etags']);

        usort($completedParts, function ($a, $b) {
            return $a['PartNumber'] - $b['PartNumber'];
        });

        try {
            $result = $s3->completeMultipartUpload([
                'Bucket' => env('AWS_BUCKET'),
                'Key' => $objectKey,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $completedParts,
                ],
                'ContentType' => 'image/jpeg',
            ]);

            $s3->putObjectAcl([
                'Bucket' => env('AWS_BUCKET'),
                'Key' => $objectKey,
                'ACL' => 'public-read',
                'ContentType' => 'image/jpeg',
            ]);

            // Retrieve the location of the completed object
            $completedObjectUrl = $result['Location'];

            return response()->json(['message' => 'Multipart upload completed successfully', 'object_url' => $completedObjectUrl]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error completing multipart upload: ' . $e->getMessage()], 500);
        }
    }
}
