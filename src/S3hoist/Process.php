<?php

/* TO-DO:

* max height and width stuff
* check referrer - if its S3 then we don't want to check the S3 exact match

*/

namespace S3hoist;

use Aws\Common\Aws;
use Aws\S3\Enum\CannedAcl;
use Aws\S3\Exception\S3Exception;

class Process {

    // Define URL API parameters
    private $parametersAvaliable = array(
        "w" => array(
                "name" => "width",
                "pattern" => "[0-9]+"
            ),
        "h" => array(
                "name" => "height",
                "pattern" => "[0-9]+"
            ),
        "mw" => array(
                "name" => "max-width",
                "pattern" => "[0-9]+"
            ),
        "mh" => array(
                "name" => "max-height",
                "pattern" => "[0-9]+"
            ),
        "q" => array(
                "name" => "quality",
                "pattern" => "[0-9]+",
                "default" => 80
            )
    );

    private $awsAccessKey;
    private $awsSecretKey;

    private $parameters = array();

    private $originalObject;
    private $originalObjectContentType;
    private $originalImageWidth;
    private $originalImageHeight;
    private $originalImageType;

    private $createdObject;
    private $createdObjectData;

    private $createdS3Path;
    private $originalS3Path;

    public $originalURL;
    public $exactURL;
    public $localPath;

    public $scriptPath = '/S3hoist/process.php';
    public $s3SubFolder = '/S3hoist';

    public $displayObject = true;
    public $debug = true;

    public $checksToPerform = array(
        'checkS3Exact',
        'checkLocalOriginal',
        'checkS3Original'
    );

    public function __construct($awsAccessKey, $awsSecretKey) {

        $this->awsAccessKey = $awsAccessKey;
        $this->awsSecretKey = $awsSecretKey;

    }

    private function arrayMerge($array1, $array2) {

        if (!is_array($array1) or !is_array($array2)) { return $array2; }
        foreach ($array2 AS $key => $value) {
            $array1[$key] = $this->arrayMerge(@$array1[$key], $value);
        }
        return $array1;

    }

    public function setParameters($parameters) {

        $this->parametersAvaliable = $this->arrayMerge($this->parametersAvaliable, $parameters);

    }

    public function go($displayObject = true){

        // Set whether object should be output to the browser
        $this->displayObject = $displayObject;

        // Process URL to check for parameters
        $proccessed = $this->processPath();

        $found = false;

        if (!$this->debug) {

            foreach($this->checksToPerform as $key => $value) {
                if($this->$value($proccessed) === true) {
                    $found = true ;
                    break;
                }
            }

        }

        if($found === false) {

            echo "No object found";

        }

    }

    private function processPath() {

        // Get S3 URL
        $exactURL = str_replace($this->scriptPath, S3_URL . $this->s3SubFolder, $_SERVER['REQUEST_URI']);

        // Get path, filename and extension
        $namePattern = "#([^\/]*)\.([^\.\/]*)\$#i";
        preg_match_all($namePattern, $exactURL, $fileName);
        $exactFilename = $fileName[0][0];
        $fileNoExtension = $fileNoParams = $fileName[1][0];
        $extension = $fileName[2][0];

        $parameterString = '';

        // Gather the parameters
        foreach ($this->parametersAvaliable as $key => $value) {

            // Gather default parameters
            $default = isset($value['default']) ? $value['default'] : NULL;
            $this->parameters[$this->parametersAvaliable[$key]['name']] = $default;

            // Gather any parameters found in the file name
            $paramPattern = '#_(' . $key . ')(' . $value['pattern'] . ')#i';
            preg_match_all($paramPattern, $fileNoExtension, $param);
            if(isset($param[1][0])) {
                $this->parameters[$this->parametersAvaliable[$param[1][0]]['name']] = $param[2][0];
            }
            $fileNoParams = preg_replace($paramPattern, '', $fileNoParams);

            // Gather any slash style parameters found in the URL
            $paramPattern = '#/(' . $key . ')_(' . $value['pattern'] . ')#i';
            preg_match_all($paramPattern, $exactURL, $param);
            if(isset($param[1][0])) {
                $this->parameters[$this->parametersAvaliable[$param[1][0]]['name']] = $param[2][0];
            }
            $exactURL = preg_replace($paramPattern, '', $exactURL);

            // Set the parameter string
            if($this->parameters[$this->parametersAvaliable[$key]['name']] !== NULL && $this->parameters[$this->parametersAvaliable[$key]['name']] !== $default) {
                $parameterString .= "_" . $key . $this->parameters[$this->parametersAvaliable[$key]['name']];
            }

        }

        $fileWithParameters = $fileNoParams . $parameterString . '.' . $extension;
        $fileNoParams = $fileNoParams . '.' . $extension;

        $this->originalURL = str_replace($exactFilename, $fileNoParams, $exactURL);
        $this->localPath = '..' . str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileNoParams), $exactURL);
        $this->originalS3Path = str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileNoParams), $exactURL);
        $this->createdS3Path = str_replace(array(S3_URL . $this->s3SubFolder, $exactFilename), array('', $fileWithParameters), $exactURL);
        $this->exactURL = str_replace($exactFilename, $fileWithParameters, $exactURL);

        if($this->debug) {
            echo $this->originalURL . "<br>";
            echo $this->localPath . "<br>";
            echo $this->originalS3Path . "<br>";
            echo $this->createdS3Path . "<br>";
            echo $this->exactURL . "<br>";
        }

    }

    private function checkS3Exact() {

        // Check if the exact object is found on S3.
        $exact = $this->fetchObject($this->exactURL);

        if ($exact['code'] === 200) {

            // exact object is found, redirect.
            header("Location: " . $this->exactURL);

            return true;

        }

        if (!$this->debug) {
            echo "exactURL: " . $this->exactURL . "<br>";
        }

        return false;

    }

    private function checkLocalOriginal() {

        if(file_exists($this->localPath) && is_file($this->localPath) && is_readable($this->localPath)) {

            $this->processObject(file_get_contents($this->localPath), true);

            return true;

        }

        if (!$this->debug) {
            echo "localPath: " . $this->localPath . "<br>";
        }

        return false;

    }

    private function checkS3Original() {

        // Check if the original object is found on S3.
        $original  = $this->fetchObject($this->originalURL, true);

        if ($original['code'] === 200) {

            // original is found, process it into the requested version.
            $this->processObject($original['data']);

            return true;

        }

        if (!$this->debug) {
            echo "originalURL: " . $this->originalURL . "<br>";
        }

        return false;

    }

    // Check if exact file exists at the URL provided
    public function fetchObject($url, $download = false) {

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, !$download);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, $download);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'code' => intval($code),
            'data' => $data
        );

    }

    // Process object
    public function processObject($originalObjectData, $sendOriginaltoS3 = false) {

        $finfo = new finfo(FILEINFO_MIME);
        $mime = strtolower(explode(';', $finfo->buffer($originalObjectData) . ";")[0]);
        $type = strtolower(explode('/', $finfo->buffer($originalObjectData) . "/")[0]);

        switch($type)
        {
            case 'image':
                $this->processImage($originalObjectData, $sendOriginaltoS3);
                break;
            default:
                $this->processFile($originalObjectData, $mime);
        }

        if($this->displayObject) {
            $this->outputObject();
        }

        $this->sendToS3($this->createdObjectData, 'created');

    }

    public function processImage($originalImageData, $sendOriginaltoS3 = false) {

        $this->originalObject = imagecreatefromstring($originalImageData);
        $originalImageProperties = getimagesizefromstring($originalImageData);
        $this->originalImageWidth = intval($originalImageProperties[0]);
        $this->originalImageHeight = intval($originalImageProperties[1]);
        $this->originalImageType = $originalImageProperties[2];
        $this->originalObjectContentType = $originalImageProperties['mime'];

        $this->resizeImage();

        if($sendOriginaltoS3) {
            $this->sendToS3($originalImageData, 'original');
        }

    }

    private function resizeImage() {

        $originalAspect = $this->originalImageWidth / $this->originalImageHeight;

        $width = $this->parameters['width'];
        $height = $this->parameters['height'];
        $desiredWidth = $width === NULL ? ($height === NULL ? $this->originalImageWidth : $this->originalImageWidth * $height / $this->originalImageHeight) : $width;
        $desiredHeight = $height === NULL ? ($width === NULL ? $this->originalImageHeight : $this->originalImageHeight * $width / $this->originalImageWidth) : $height;
        $desiredAspect = $desiredWidth / $desiredHeight;

        if ($originalAspect >= $desiredAspect) {
            // If image is wider than thumbnail (in aspect ratio sense)
            $newHeight = $desiredHeight;
            $newWidth = $this->originalImageWidth / ($this->originalImageHeight / $desiredHeight);
        } else {
            // If the thumbnail is wider than the image
            $newWidth = $desiredWidth;
            $newHeight = $this->originalImageHeight / ($this->originalImageWidth / $desiredWidth);
        }

        $this->createdObject = imagecreatetruecolor($desiredWidth, $desiredHeight);
        imagecopyresampled(
            $this->createdObject,
            $this->originalObject,
            0 - ($newWidth - $desiredWidth) / 2,
            0 - ($newHeight - $desiredHeight) / 2,
            0,
            0,
            $newWidth,
            $newHeight,
            $this->originalImageWidth,
            $this->originalImageHeight
        );

        ob_start();
            switch($this->originalImageType)
            {
                case IMAGETYPE_GIF:
                    imagegif($this->createdObject);
                    break;
                case IMAGETYPE_PNG:
                    imagepng($this->createdObject, NULL, $this->parameters['quality']);
                    break;
                default: //IMAGETYPE_JPEG
                    imagejpeg($this->createdObject, NULL, $this->parameters['quality']);
            }
        $this->createdObjectData = ob_get_clean();

        imagedestroy($this->originalObject);
        imagedestroy($this->createdObject);

    }

    public function processFile($originalObjectData, $mime) {

        $this->originalObjectContentType = $mime;
        $this->createdObjectData = $originalObjectData;

    }

    public function outputObject() {

        header('Content-type: ' . $this->originalObjectContentType);

        echo $this->createdObjectData;

    }

    private function sendToS3($data, $type) {

        switch($type)
        {
            case 'original':
                $path = $this->originalS3Path;
                break;
            default:
                $path = $this->createdS3Path;
        }

        $path = ltrim($this->s3SubFolder . $path, '/');

        // Instantiate an S3 client
        $s3 = Aws\Common\Aws::factory(array(
            'key'    => $this->awsAccessKey,
            'secret' => $this->awsSecretKey
        ))->get('s3');

        try {
            $s3->putObject(array(
                'Bucket'      => BUCKET,
                'Key'         => $path,
                'ContentType' => $this->originalObjectContentType,
                'Body'        => $data,
                'ACL'         => Aws\S3\Enum\CannedAcl::PUBLIC_READ
            ));
        } catch (S3Exception $e) {
            echo "The file was not uploaded.\n";
        }

    }

}
