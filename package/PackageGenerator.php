<?php

namespace Sugarcrm\ProfessorM;

use function array_push;
use const DIRECTORY_SEPARATOR;
use function file_get_contents;
use function getcwd;

/**
 * Class PackageGenerator
 * @package Sugarcrm\ProfessorM
 */
class PackageGenerator
{
    protected $cwd;

    public function __construct(){
        $this -> cwd = getcwd();
    }

    /*
     * $cwd defaults to the current working directory so you should only need to use this function if you are testing
     */
    public function setCwd($pathOfWorkingDirectory){
        $this -> cwd = $pathOfWorkingDirectory;
    }

    public function shouldIncludeFileInZip($fileRelative)
    {
        /*
         * We want to exclude files in the following directories:
         *    custom/application/Ext
         *    custom/modules/.../Ext
         * The regular expressions allow for file paths with forward or backward slashes */
        if(preg_match('/.*custom[\/\\\]application[\/\\\]Ext[\/\\\].*/', $fileRelative) or
            preg_match('/.*custom[\/\\\]modules[\/\\\].+[\/\\\]Ext[\/\\\].*/', $fileRelative)){
            return false;
        }
        return true;
    }

    /*
     * Get the version that should be used for the zip.  If a version
     * is not passed as a param, the function checks for a file named
     * "version" and gets the version out of the file.
     */
    public function getVersion($versionPassedToScript){
        if (empty($versionPassedToScript)) {
            $pathToVersionFile = $this -> cwd . DIRECTORY_SEPARATOR . "version";
            if (file_exists($pathToVersionFile)) {
                return file_get_contents($pathToVersionFile);
            }
        }
        return $versionPassedToScript;
    }

    /*
     * Returns the relative file path for the zip file that will be created.
     * @throws \Exception if $version is empty.
     * Will make a releases directory if one does not already exists.
     */
    public function getZipFilePath($version, $packageID, $command){
        if (empty($version)){
            throw new \Exception("Use $command [version]\n");
        }

        $id = "{$packageID}-{$version}";

        $directory = "releases";
        if(!is_dir($directory)){
            mkdir($directory);
        }

        $zipFile = $directory . DIRECTORY_SEPARATOR . "sugarcrm-{$id}.zip";
        return $zipFile;
    }

    /**
     * Iterate over the files located in the $srcDirectory and return an array that contains a
     * array of files to include in the zip and an array of files to exclude from the zip
     *
     * @param $srcDirectory
     * @return array
     */
    public function getFileArraysForZip($srcDirectory)
    {
        $filesToInclude = array();
        $filesToExclude = array();

        $basePath = $this->cwd . DIRECTORY_SEPARATOR . $srcDirectory;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if ($file->isFile()) {

                $fileReal = $file->getPath() . DIRECTORY_SEPARATOR . $file->getBasename();
                $fileRelative = $srcDirectory . str_replace($basePath, '', $fileReal);
                $fileArray = array("fileReal" => $fileReal, "fileRelative" => $fileRelative);

                if ($this->shouldIncludeFileInZip($fileRelative)) {
                    array_push($filesToInclude, $fileArray);
                } else {
                    array_push($filesToExclude, $fileArray);
                }

            }
        }
        return array("filesToInclude" => $filesToInclude, "filesToExclude" => $filesToExclude);

    }

    /**
     * Creates and opens a new zip archive
     *
     * @param $version
     * @param $packageID
     * @param $command
     * @return \ZipArchive
     * @throws \Exception if a zip file with the same name already exists
     */
    public function openZip($version, $packageID, $command){
        $zipFile = $this -> getZipFilePath($version, $packageID, $command);

        if (file_exists($zipFile)) {
            throw new \Exception("Error:  Release $zipFile already exists, so a new zip was not created. To generate a"
                . " new zip, either delete the"
                . " existing zip file or update the version number in the version file AND then run the script to build the"
                . " module again. \n");
        }

        echo "Creating {$zipFile} ... \n";
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        return $zip;
    }

    public function closeZip($zip){
        $filename = basename($zip -> filename);
        $zip->close();
        echo "Done creating $filename\n\n";
        return $zip;
    }

    /*
     * Adds the files listed in $filesToInclude to the $zip
     */
    public function addFilesToZip($zip, $filesToInclude){
        foreach($filesToInclude as $file) {
            echo " [*] " . $file['fileRelative'] . "\n";
            $zip->addFile($file['fileReal'], $file['fileRelative']);
        }
        return $zip;
    }

    /**
     * @param $filesToInclude
     * @param $installdefs
     * @param $srcDirectory
     * @return mixed
     */
    public function addFilesToInstalldefs($filesToInclude, $installdefs, $srcDirectory){
        foreach($filesToInclude as $file) {
            $installdefs['copy'][] = array(
                'from' => '<basepath>/' . $file['fileRelative'],
                'to' => preg_replace('/^' . $srcDirectory .'[\/\\\](.*)/', '$1', $file['fileRelative']),
            );
        }
        return $installdefs;
    }

    /*
     * Outputs a list of files that were excluded from the zip
     */
    public function echoExcludedFiles($filesToExclude){
        if (!empty($filesToExclude)){
            echo "The following files were excluded from the zip: \n";
            foreach($filesToExclude as $file) {
                echo " [*] " . $file["fileRelative"] . "\n";
            }
        }
    }

    /*
     * Creates manifest.php where the content of the file is made up of the $manifestContent and $installdefs.
     * The resulting manifest.php file is placed in the $zip.
     */
    public function generateManifest($manifestContent, $installdefs, $zip){
        $manifestContent = sprintf(
            "<?php\n\$manifest = %s;\n\$installdefs = %s;\n",
            var_export($manifestContent, true),
            var_export($installdefs, true)
        );
        $zip->addFromString('manifest.php', $manifestContent);
        return $zip;
    }

    /*
     * Creates the zip for the Module Loadable Package
     */
    public function generateZip($version, $packageID, $command, $srcDirectory, $manifestContent, $installdefs ){
        $zip = $this -> openZip($version, $packageID, $command);

        $arrayOfFiles = $this -> getFileArraysForZip($srcDirectory);
        $filesToInclude = $arrayOfFiles["filesToInclude"];
        $filesToExclude = $arrayOfFiles["filesToExclude"];

        $zip = $this -> addFilesToZip($zip, $filesToInclude);
        $installdefs = $this -> addFilesToInstalldefs($filesToInclude, $installdefs, $srcDirectory);

        $zip = $this -> generateManifest($manifestContent, $installdefs, $zip);
        $zip = $this -> closeZip($zip);

        $this -> echoExcludedFiles($filesToExclude);
        return $zip;
    }
}
