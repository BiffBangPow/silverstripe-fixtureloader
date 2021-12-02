<?php

namespace BiffBangPow\FixtureLoader\Service;

use DNADesign\Elemental\Models\BaseElement;
use DNADesign\Elemental\Models\ElementContent;
use Faker\Factory;
use Faker\Generator;
use Page;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FixtureBlueprint;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\SiteConfig\SiteConfig;

class FixtureLoader
{
    use Configurable;

    /**
     * @var string
     */
    const DATE_FORMAT = 'Y-m-d';

    /**
     * @var string
     * @config
     */
    private static $fixtures_dir;

    /**
     * @var FixtureFactory
     */
    private $fixtureFactory;

    /**
     * @var Generator
     */
    private $faker;

    /**
     * @config
     * @var array
     */
    private static $extra_page_classes = [
        ErrorPage::class,
    ];

    /**
     * @config
     * @var array
     */
    private $extra_elements_classes = [
        ElementContent::class,
    ];

    /**
     * @var array
     */
    private $fileClasses = [
        File::class,
        Image::class,
    ];

    /**
     * @param FixtureFactory $fixtureFactory
     */
    public function __construct(FixtureFactory $fixtureFactory)
    {
        $this->fixtureFactory = $fixtureFactory;
        $this->faker = Factory::create();
    }

    /**
     * @param FixtureFactory $fixtureFactory
     * @param array $fixtureFiles
     * @return void
     */
    public static function loadInto(FixtureFactory $fixtureFactory)
    {
        $fixtureFiles = self::getFixtureFiles();
        $fixtureLoader = new self($fixtureFactory);
        $fixtureLoader->loadDefinitions();
        $fixtureLoader->loadFixtureFiles($fixtureFiles);
        $fixtureLoader->publishAllPages();
    }

    private static function getFixtureFiles()
    {
        $fixturesDir = self::config()->get('fixtures_dir');
        if (($fixturesDir == "") || (!is_dir($fixturesDir))) {
            $fixturesDir = rtrim(Director::baseFolder(), '/') . '/app/fixtures';
        }

        echo "Scanning " . $fixturesDir . "\n";
        $fileList = glob(rtrim($fixturesDir, '/') . '/*.yml');
        natsort($fileList);
        return $fileList;
    }

    /**
     * @return void
     */
    public function loadDefinitions()
    {
        $this->fixtureFactory->define(Member::class, $this->createMemberBluePrint());

        foreach (
            array_merge($this->getClassesInNamespace('BiffBangPow\Page'), $this->config()->get('extra_page_classes')) as $pageClass
        ) {
            $this->fixtureFactory->define($pageClass, $this->createPublishedBluePrint($pageClass));
        }

        foreach (
            array_merge($this->getClassesInNamespace('BiffBangPow\Element'), $this->config()->get('extra_elements_classes')) as $elementClass
        ) {
            $this->fixtureFactory->define($elementClass, $this->createElementBluePrint($elementClass));
        }

        foreach ($this->fileClasses as $fileClass) {
            $this->fixtureFactory->define($fileClass, $this->createFileBluePrint($fileClass));
        }

        $this->fixtureFactory->define(SiteConfig::class, $this->createSiteConfigBluePrint(SiteConfig::class));
    }

    /**
     * @return FixtureBlueprint
     */
    private function createMemberBluePrint()
    {
        $bluePrint = Injector::inst()->create(FixtureBlueprint::class, Member::class);
        $bluePrint->addCallback('afterCreate', function ($object, $identifier, $data) {
            if ($object->Password === null || $object->Password === '') {
                $object->Password = 'development';
            }
            /** @var DataObject $object */
            $object->write();
        });
        return $bluePrint;
    }

    public function getClassesInNamespace(string $namespace): array
    {
        $namespacePath = $this->translateNamespacePath($namespace);
        if ($namespacePath === '') {
            return [];
        }

        return $this->searchClasses($namespace, $namespacePath);
    }

    protected function translateNamespacePath(string $namespace): string
    {
        $rootPath = __DIR__ . DIRECTORY_SEPARATOR;

        $nsParts = explode('\\', $namespace);
        array_shift($nsParts);

        if (empty($nsParts)) {
            return '';
        }

        return realpath($rootPath . implode(DIRECTORY_SEPARATOR, $nsParts)) ?: '';
    }

    private function searchClasses(string $namespace, string $namespacePath): array
    {
        $classes = [];

        /**
         * @var \RecursiveDirectoryIterator $iterator
         * @var \SplFileInfo $item
         */
        foreach ($iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($namespacePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        ) as $item) {
            if ($item->isDir()) {
                $nextPath = $iterator->current()->getPathname();
                $nextNamespace = $namespace . '\\' . $item->getFilename();
                $classes = array_merge($classes, self::searchClasses($nextNamespace, $nextPath));
                continue;
            }
            if ($item->isFile() && $item->getExtension() === 'php') {
                $class = $namespace . '\\' . $item->getBasename('.php');
                if (!class_exists($class)) {
                    continue;
                }
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @param string $class
     * @return FixtureBlueprint
     */
    private function createPublishedBluePrint(string $class)
    {
        $bluePrint = Injector::inst()->create(FixtureBlueprint::class, $class);
        $bluePrint->addCallback('afterCreate', function ($page) {
            /** @var $page Page */
            $page->publish('Stage', 'Live');
        });
        return $bluePrint;
    }

    /**
     * @param string $class
     * @return FixtureBlueprint
     */
    private function createElementBluePrint(string $class)
    {
        $bluePrint = Injector::inst()->create(FixtureBlueprint::class, $class);

        $bluePrint->addCallback('afterCreate', function ($element) {

            $pageId = $element->ParentID;
            $parentPage = SiteTree::get_by_id($pageId);
            $parentPageElementalArea = $parentPage->ElementalArea();
            $element->ParentID = $parentPageElementalArea->ID;
            $element->write();

            /** @var $page BaseElement */
            $element->publish('Stage', 'Live');
        });

        return $bluePrint;
    }

    /**
     * @param string $class
     * @return FixtureBluePrint
     */
    private function createFileBluePrint(string $class = File::class)
    {
        $fileBluePrint = Injector::inst()->create(FixtureBlueprint::class, $class);
        $fileBluePrint->addCallback('afterCreate', function (File $file, $identifier, $data) {
            $appRoot = rtrim(Director::baseFolder(), '/') . '/';
            $source = $appRoot . $data['Source'];

            $file->setFromLocalFile($source);
            $file->CanViewType = 'Anyone';
            $file->CanEditType = 'Anyone';
            $file->write();
            $file->updateFilesystem();
            $file->publishSingle();
        });
        return $fileBluePrint;
    }

    /**
     * @param string $class
     * @return FixtureBluePrint
     */
    private function createSiteConfigBluePrint(string $class = SiteConfig::class)
    {
        $fileBluePrint = Injector::inst()->create(FixtureBlueprint::class, $class);
        $fileBluePrint->addCallback('beforeCreate', function () {

            $siteconfig = SiteConfig::current_site_config();
            $siteconfig->delete();
        });
        return $fileBluePrint;
    }

    /**
     * @param array $fixtureFiles
     * @return void
     */
    public function loadFixtureFiles(array $fixtureFiles = [])
    {
        foreach ($fixtureFiles as $path) {
            echo 'Loading: ' . $path . "\n";
            /** @var $fixture YamlFixture */
            $fixture = Injector::inst()->create(YamlFixture::class, $path);
            $fixture->writeInto($this->fixtureFactory);
        }
    }

    /**
     * @return void
     */
    private function publishAllPages()
    {
        $pages = Page::get();

        foreach ($pages as $page) {
            /** @var $page Page */
            $page->publishRecursive();
        }
    }

}