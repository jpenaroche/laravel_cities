<?php namespace Igaster\LaravelCities\commands;

use Illuminate\Console\Command;
use Igaster\LaravelCities\commands\helpers\geoItem;
use Igaster\LaravelCities\commands\helpers\geoCollection;


class seedGeoFile extends Command
{
    protected $signature = 'geo:seed {country?} {--append}';
    protected $description = 'Load + Parse + Save to DB a geodata file.';

    private $pdo;

    public function __construct() {
        parent::__construct();
        $this->pdo = \DB::connection()->getPdo(\PDO::FETCH_ASSOC);
        if (!\Schema::hasTable('geo'))
            return;

        $this->geoItems = new geoCollection();
    }

    public function buildDbTree($item, $count = 1, $depth = 0){
        $item->left=$count++;
        $item->depth=$depth;
        foreach ($item->getChildren() as $child) {
            $count = $this->buildDbTree($child, $count, $depth+1);
        }
        $item->right=$count++;
        return $count;
    }

    public function printTree($item){
        $levelStr= str_repeat('--', $item->depth);
        $this->info(sprintf("%s %s [%d,%d]", $levelStr, $item->getName(),$item->left,$item->right));
        foreach ($item->getChildren() as $child)
            $this->printTree($child);
    }

    public function handle() {
        $start = microtime(true);

        $fileName = $this->argument('country') ? strtoupper($this->argument('country')) : 'allCountries';
        $fileName = storage_path("geo/{$fileName}.txt");
        $append =  $this->option('append');

        // Read Raw file
        $this->info("Reading File '$fileName'");
        $filesize = filesize($fileName);
        $handle = fopen($fileName, 'r');
        $count = 0;

        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        while (($line = fgets($handle)) !== false) {
            // ignore empty lines and comments
            if ( ! $line or $line === '' or strpos($line, '#') === 0) continue;

            // Convert TAB sepereted line to array
            $line = explode("\t", $line);

            // Check for errors
            if(count($line)!== 19) dd($line[0],$line[2]);

            switch ($line[7]) {
                case 'PCLI':    // Country
                case 'PPLC':    // Capital
                case 'ADM1':
                case 'ADM2':
                case 'ADM3':
                    $this->geoItems->add(new geoItem($line, $this->geoItems));
                    $count++;
                    break;
            }
            $progress = ftell($handle)/$filesize*100;
            $progressBar->setProgress($progress);
        }
        $progressBar->finish();
        $this->info(" Finished Reading File. $count items loaded</info>");

        // Read hierarchy
        $fileName = storage_path('geo/hierarchy.txt');
        $this->info("Opening File '$fileName'</info>");
        $handle = fopen($fileName, 'r');
        $filesize = filesize($fileName);
        $count = 0;
        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        while (($line = fgetcsv($handle, 0, "\t")) !== false) {
            $parent = $item=$this->geoItems->findGeoId($line[0]);
            $child  = $item=$this->geoItems->findGeoId($line[1]);

            if( $parent !== null && $child !== null){
                $parent->addChild($line[1]);
                $child->setParent($line[0]);
                $count++;
            }
            $progress = ftell($handle)/$filesize*100;
            $progressBar->setProgress($progress);
        }
        $this->info(" Hierarcy building completed. $count items loaded</info>");

        //Read Gmap Iframes
        $iframes = [];
        $fileName = storage_path('geo/iframes.txt');
        $this->info("Opening File '$fileName'</info>");
        $handle = fopen($fileName, 'r');
        $filesize = filesize($fileName);
        $count = 0;
        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        while (($line = fgetcsv($handle, 0, "\t")) !== false) {
            $line = explode(',',$line[0]);
            $iframes [$line[0]] = $line[1];
            $count++;
            $progress = ftell($handle)/$filesize*100;
            $progressBar->setProgress($progress);
        }

        $this->info(" Gmap Iframes building completed. $count items loaded</info>");

        // Build Tree
        $count = 0; $countOrphan = 0;
        $result=\DB::table('geo')->max('right');
        $maxBoundary = $result ?  $result+1 : 0;
        foreach ($this->geoItems->items as $item) {
            if($item->parentId === null){

                if($item->data[7] !== 'PCLI'){
                    // $this->info("- Skiping Orphan {$item->data[2]} #{$item->data[0]}");
                    $countOrphan++;
                    continue;
                }

                $count++;
                $this->info("+ Building Tree for Country: {$item->data[2]} #{$item->data[0]}");

                $maxBoundary=$this->buildDbTree($item,$maxBoundary,0);
                // $this->printTree($item,$output);
            }
        }
        $this->info("Finished: {$count} Countries imported.  $countOrphan orphan items skiped</info>");


        // Empty Table
        if (!$append){
            $this->info("Truncating 'geo' table...");
            \DB::table('geo')->truncate();
        }

        // Store Tree in DB
        $this->info("Writing in Database</info>");
        $count = 0;
        $totalCount = count($this->geoItems->items);
        $progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output, 100);
        foreach ($this->geoItems->items as $item) {
            $name = substr($item->data[2],0,40);
            $alternames=json_encode($item->data[3]);
//            if($alternames)
//                $alternames=json_encode(['es'=>$alternames[5],'en'=>$alternames[0]]);
//            else
//                $alternames=json_encode(['es'=>$name,'en'=>$name]);

            \DB::table('geo')->insert(
                [
                    'id'           => $item->getId(),
                    'parent_id'    => $item->parentId,
                    'left'         => $item->left,
                    'right'        => $item->right,
                    'depth'        => $item->depth,
                    'name'         => $name,
                    'alternames'   => $alternames,
                    'country'      => $item->data[8],
                    'level'        => $item->data[7],
                    'population'   => $item->data[14],
                    'lat'          => $item->data[4],
                    'long'         => $item->data[5],
                    'iframe'       => @$iframes[$item->getId()]
                ]
            );

            $progress = $count++/$totalCount*100;
            $progressBar->setProgress($progress);
        }
        $progressBar->finish();

        $this->info(" Done</info>");
        $time_elapsed_secs = microtime(true) - $start;
        $this->info("Timing: $time_elapsed_secs sec</info>");
    }
}
