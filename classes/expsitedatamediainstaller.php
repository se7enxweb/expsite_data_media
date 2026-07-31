<?php

class expSiteDataMediaInstaller extends expLayoutsSiteInstaller
{
    public $replaceConflicts = false;

    public function __construct( $dataPath = null, $storagePath = null )
    {
        $ini = eZINI::instance( 'expsite_data_media.ini' );
        $dataPath = $dataPath ?: $ini->variable( 'DataMediaSettings', 'DataPath' );
        $storagePath = $storagePath ?: $ini->variable( 'DataMediaSettings', 'StoragePath' );
        parent::__construct( $dataPath, $storagePath );
    }

    public function installDataPack()
    {
        $dataFileName = $this->replaceConflicts ? 'data-replace.sql' : 'data.sql';
        $dataFile = $this->installerDataPath . '/' . $dataFileName;
        if ( !file_exists( $dataFile ) )
        {
            $this->output[] = 'No data file at ' . $dataFile;
            return $this->output;
        }

        $this->output[] = 'Loading media demo data from ' . $dataFile;
        $dbPath = 'var/storage/sqlite3/sqlite.db';
        $cmd = 'sqlite3 ' . escapeshellarg( $dbPath ) . ' < ' . escapeshellarg( $dataFile ) . ' 2>&1';

        // Close the eZ DB connection before shelling out to sqlite3 so the
        // SQLite file is not locked while the external client imports the dump.
        $db = eZDB::instance();
        $db->close();
        eZDB::setInstance( null );

        exec( $cmd, $cmdOutput, $returnCode );

        eZDB::instance();

        if ( $returnCode !== 0 )
        {
            $this->output[] = 'SQL import failed (exit ' . $returnCode . '): ' . implode( "\n", $cmdOutput );
        }
        else
        {
            $this->output[] = 'SQL import complete';
        }

        $this->importBinaries();
        return $this->output;
    }

    /**
     * Override the base installer so the media pack merges binaries into an
     * existing storage tree instead of refusing when the destination is not empty.
     */
    public function importBinaries( $source = null, $destination = null )
    {
        $source = $source !== null ? $source : $this->installerDataPath . '/storage';
        $destination = $destination !== null ? $destination : $this->storagePath;

        if ( !is_dir( $source ) )
        {
            $this->output[] = "No storage source at $source";
            return false;
        }

        $this->recursiveCopy( $source, $destination );
        $this->output[] = "Merged binaries to $destination";
        return true;
    }
}