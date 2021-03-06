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

require_once(ENHANCED_INDEXER_INC.'inc/Doku_Indexer_Enhanced.php');
/**
 * Update the Search Index from command line
 */
class EnhancedIndexerCLI extends DokuCLI {

    private $quiet = false;
    private $clear = false;
    private $force = false;
    private $namespace = '';
    private $removeLocks = false;
    private $exit = false;
    private $clean = true;
    private $maxRuns = 0;
    private $startOffset = 0;

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
        
        $options->registerOption(
            'max-runs',
            'Restart after indexing n items',
            'r',
            true
        );
        
        $options->registerOption(
            'start',
            'start at offset',
            's',
            true
        );
    }
    
    
    public function __destruct() 
    {
        $this->cleanup();
    }
    
    private function cleanup()
    {
        if($this->clean == false) {
            $this->quietecho('Saving Indexes...');
            enhanced_idx_get_indexer()->flushIndexes();
            $this->quietecho("done\n");
            $this->clean = true;
        }
        
        $this->removeLocks();
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
        $this->maxRuns = $options->getOpt('max-runs', 0);
        $this->startOffset = $options->getOpt('start', 0);
        
        $id = $options->getOpt('id');
        
        if($this->removeLocks) {
            $this->removeLocks();
        }
        
        if($id) {
            $this->index($id);
            $this->quietecho("done.\n");
        } else {

            if($this->clear) {
                $this->clearindex();
            }

            $this->update();
        }
        
    }

    /**
     * Update the index
     */
    function update() {
        global $conf;
        $data = array();
        if($this->lock() == false) {
            $this->error('unable to get lock, bailing');
            exit(1);
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

        $cnt = 0;
        
        
        $length = count($data);
        for($i=$this->startOffset; $i < $length; $i++) {
            
            if(($this->index($idPrefix.$data[$i]['id']))) {
                $cnt++;
                $this->clean = false;
            } 
                    
            if($this->exit) {
                exit();
            }
            
            if(memory_get_usage() > return_bytes(ini_get('memory_limit')) * 0.8) { 
                // we've used up 80% memory try again.
                $this->error('Memory almost full, restarting');
                $this->restart($i+1);
            }
            
            if($this->maxRuns && $cnt >= $this->maxRuns) {
                $this->error('Max runs reached '.$cnt.', restarting');
                $this->restart($i+1);
            }
        }
    }
    
    function restart($start = 0)
    {
        global $argv;
        $this->cleanup();
        $args = $argv;
        array_unshift($args, '-d', 'memory_limit='.ini_get('memory_limit'));
        
        foreach($args as $key => $arg) {
            if($arg == '--clear' || $arg == '-c') {
                $args[$key] = '--force';
            }
        }
        
        array_push($args, '--start', $start);
        
        pcntl_exec($_SERVER['_'], $args);
        exit();
    }

    /**
     * Index the given page
     *
     * @param string $id
     */
    function index($id) {
        $this->quietecho("$id... ");
        return enhanced_idx_addPage($id, !$this->quiet, $this->force || $this->clear);
    }

    /**
     * Clear all index files
     */
    function clearindex() {
        $this->quietecho("Clearing index... ");
        enhanced_idx_get_indexer()->clear();
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
    
    public function removeLocks()
    {
        global $conf;
        
        $this->quietecho('clearing lock...');
        $return = true;
        
        if(is_dir($conf['lockdir'].'/_enhanced_indexer.lock') && !rmdir($conf['lockdir'].'/_enhanced_indexer.lock')) {
            $this->error('failed to remove '.$conf['lockdir'].'/_enhanced_indexer.lock something is wrong');
            $return  = false;
        }
        
        if(is_dir($conf['lockdir'].'/_indexer.lock') && !rmdir($conf['lockdir'].'/_indexer.lock')) {
            $this->error('failed to remove '.$conf['lockdir'].'/_indexer.lock something is wrong');
            $return  = false;
        }
        $this->quietecho('done.\n');
        
        return $return;
    }
    
    public function sigInt()
    {
        $this->exit = true;
    }
}

// Main
$cli = new EnhancedIndexerCLI();

if(function_exists('pcntl_signal')) {
    // ensure things exit cleanly with ctrl+c
    declare(ticks = 10);

    pcntl_signal(SIGINT, array($cli, 'sigInt'));  
    pcntl_signal(SIGTERM, array($cli, 'sigInt'));
}

$conf['cachetime'] = 60 * 60; // default is -1 which means cache isnt' used :(

$cli->run();


function return_bytes ($size_str)
{
    switch (substr ($size_str, -1))
    {
        case 'M': case 'm': return (int)$size_str * 1048576;
        case 'K': case 'k': return (int)$size_str * 1024;
        case 'G': case 'g': return (int)$size_str * 1073741824;
        default: return $size_str;
    }
}