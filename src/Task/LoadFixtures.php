<?php
namespace BiffBangPow\FixtureLoader\Task;

use BiffBangPow\FixtureLoader\Service\FixtureLoader;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\ORM\DB;

class LoadFixtures extends BuildTask
{
    private static $segment = 'LoadFixtures';
    protected $title = 'Build Dev Fixtures';
    protected $description = 'Build all the dev fixtures';
    protected $enabled = true;

    public function run($request)
    {
        DB::get_conn()->clearAllData();

        //delete the assets folder
        Filesystem::removeFolder(BASE_PATH . '/public/assets', true);

        //recreate .gitignore file
        $gitignorefile = fopen(BASE_PATH . '/public/assets/.gitignore', "w");
        fwrite($gitignorefile, "/**/*\n!.gitignore\n!web.config\n");
        fclose($gitignorefile);

        /**
         * @var $fixtureFactory FixtureFactory
         */
        $fixtureFactory = Injector::inst()->create(FixtureFactory::class);
        FixtureLoader::loadInto($fixtureFactory);
    }
}