#!/usr/bin/php
<?php
if(!defined('DOKU_INC')) {
    define('DOKU_INC', realpath(dirname(__FILE__).'/../../../../').'/');
}
define('ENHANCED_INDEXER_INC', realpath(dirname(__FILE__).'/../').'/');

define('NOSESSION', 1);
require_once(DOKU_INC.'inc/init.php');

if(class_exists('DokuCLI') == false) {
    require_once(ENHANCED_INDEXER_INC.'inc/cli.php');
} 
/**
 * Update the Search Index from command line
 */
class EnhancedIndexerCLI extends DokuCLI {

    private $quiet = false;
    private $clear = false;
    private $force = false;
    private $namespace = '';
    private $removeLocks = false;

    /**
     * Register options and arguments on the given $options object
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function setup(DokuCLI_Options $options) {
        $options->setHelp(
            'Updates the searchindex by indexing all new or changed pages. When the -c option is '.
            'given the index is cleared first.'
        );

        $options->registerOption(
            'clear',
            'clear the index before updating',
            'c'
        );
        
        $options->registerOption(
            'force',
            'force the index rebuilding, skip date check',
            'f'
        );
        
        $options->registerOption(
            'namespace',
            'Only update items in namespace',
            'n',
            true // needs arg
        );
        
        $options->registerOption(
            'quiet',
            'don\'t produce any output',
            'q'
        );
        
        $options->registerOption(
            'id',
            'only update specific id',
            'i',
            true // needs arg
        );
        
        $options->registerOption(
            'remove-locks',
            'remove any locks on the indexer',
            'l'
        );
    }

    /**
     * Your main program
     *
     * Arguments and options have been parsed when this is run
     *
     * @param DokuCLI_Options $options
     * @return void
     */
    protected function main(DokuCLI_Options $options) {
        $this->clear = $options->getOpt('clear');
        $this->quiet = $options->getOpt('quiet');
        $this->force = $options->getOpt('force');
        $this->namespace = $options->getOpt('namespace', '');
        $this->removeLocks = $options->getOpt('remove-locks', '');
        
        $id = $options->getOpt('id');
        
        if($id) {
            $this->index($id);
            $this->quietecho("done.\n");
            return;
        }

        if($this->clear) {
            $this->clearindex();
        }

        $this->update();
    }

    /**
     * Update the index
     */
    function update() {
        global $conf;
        $data = array();
        if($this->lock() == false) {
            $this->error('unable to get lock, bailing');
            return;
        }
        $this->quietecho("Searching pages... ");
        if($this->namespace) {
            $dir = $conf['datadir'].'/'. str_replace(':', DIRECTORY_SEPARATOR, $this->namespace);
            $idPrefix = $this->namespace.':';
        } else {
            $dir = $conf['datadir'];
            $idPrefix = '';
        }
        search($data, $dir, 'search_allpages', array('skipacl' => true));
        $this->quietecho(count($data)." pages found.\n");

        foreach($data as $val) {
            $this->index($idPrefix.$val['id']);
        }
        $this->unlock();
    }

    /**
     * Index the given page
     *
     * @param string $id
     */
    function index($id) {
        $this->quietecho("$id... ");
        idx_addPage($id, !$this->quiet, $this->force);
        $this->quietecho("done.\n");
    }

    /**
     * Clear all index files
     */
    function clearindex() {
        $this->quietecho("Clearing index... ");
        idx_get_indexer()->clear();
        $this->quietecho("done.\n");
    }

    /**
     * Print message if not supressed
     *
     * @param string $msg
     */
    function quietecho($msg) {
        if(!$this->quiet) {
            echo $msg;
        }
    }
    
    /**
     * Lock the indexer.
     */
    protected function lock() {
        global $conf;
        $status = true;
        
        $lock = $conf['lockdir'].'/_enhanced_indexer.lock';
        if (!@mkdir($lock, $conf['dmode'])) {
            if(is_dir($lock) && $this->removeLocks) {
                // looks like a stale lock - remove it
                if (!@rmdir($lock)) {
                    $status = "removing the stale lock failed";
                    return false;
                } else {
                    $status = "stale lock removed";
                    return true;
                }
            } else {
                return false;
            }
        }
        if (!empty($conf['dperm'])) {
            chmod($lock, $conf['dperm']);
        }
        return $status;
    }

    /**
     * Release the indexer lock.
     *
     * @author Tom N Harris <tnharris@whoopdedo.org>
     */
    protected function unlock() {
        global $conf;
        @rmdir($conf['lockdir'].'/_enhanced_indexer.lock');
        return true;
    }
    
}

// Main
$cli = new EnhancedIndexerCLI();
$cli->run();