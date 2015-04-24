<?php

/*
 * This file is part of Dbup.
 *
 * (c) Masao Maeda <brt.river@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dbup;

use Dbup\Database\MysqlClient;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Dbup\Exception\RuntimeException;

/**
 * @author Masao Maeda <brt.river@gmail.com>
 */
class Application extends BaseApplication
{
    const NAME = 'dbup';
    const VERSION = '0.5';
    /** sql file pattern */
    const PATTERN = '/^V(\d+?)__.*\.sql$/i';
    public $db = null;
    public $baseDir = '.';
    public $sqlFilesDir;
    public $appliedFilesDir;
    /** @var string Logo AA */
    private static $logo =<<<EOL
       _ _
     | | |
   __| | |__  _   _ _ __
  / _` | '_ \| | | | '_ \
 | (_| | |_) | |_| | |_) |
  \__,_|_.__/ \__,_| .__/
                   | |
                   |_|
 simple migration tool

EOL;

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);
        $this->sqlFilesDir =  $this->baseDir . '/sql';
        $this->appliedFilesDir =  $this->baseDir . '/.dbup/applied';
    }

    public function getIni()
    {
        return $this->baseDir . '/.dbup/properties.ini';
    }

    public function getFinder()
    {
        return new Finder();
    }

    public function createClient($host, $user, $pass, $name)
    {
        $this->db = new MysqlClient($host, $user, $pass, $name);
    }

    public function parseIniFile($path)
    {
        $ini = file_get_contents($path);
        $replaced = preg_replace_callback('/%%(DBUP_[^%]+)%%/', function ($matches) {
            list($whole, $key) = $matches;
            return isset($_SERVER[$key]) ? $_SERVER[$key] : $whole;
        }, $ini);

        return parse_ini_string($replaced, true);
    }

    public function setConfigFromIni($path)
    {
        $parse = $this->parseIniFile($path);

        if (!isset($parse['db'])) {
            throw new RuntimeException('cannot find [db] section in your properties.ini');
        }

        $db = $parse['db'];

        $host = (isset($db['host']))? $db['host']: '';
        $user = (isset($db['user']))? $db['user']: '';
        $pass = (isset($db['pass']))? $db['pass']: '';
        $name = (isset($db['name']))? $db['name']: '';

        if (isset($parse['path'])) {

            $path = $parse['path'];

            $this->sqlFilesDir = (isset($path['sql']))? $path['sql']: $this->sqlFilesDir;
            $this->appliedFilesDir = (isset($path['applied']))? $path['applied']: $this->appliedFilesDir;
        }

        $this->createClient($host, $user, $pass, $name);
    }

    public function getHelp()
    {
        return self::$logo . parent::getHelp();
    }

    /**
     * sort closure for Finder
     * @return callable sort closure for Finder
     */
    public function sort()
    {
        return function (\SplFileInfo $a, \SplFileInfo $b) {
            preg_match(self::PATTERN, $a->getFileName(), $version_a);
            preg_match(self::PATTERN, $b->getFileName(), $version_b);
            return ((int)$version_a[1] < (int)$version_b[1]) ? -1 : 1;
        };
    }

    /**
     * get sql files
     * @return Finder
     */
    public function getSqlFiles()
    {
        $sqlFinder = $this->getFinder();

        $files = $sqlFinder->files()
            ->in($this->sqlFilesDir)
            ->name(self::PATTERN)
            ->sort($this->sort())
        ;

        return $files;
    }

    /**
     * find sql file by the file name
     * @param $fileName
     * @return mixed
     * @throws Exception\RuntimeException
     */
    public function getSqlFileByName($fileName)
    {
        $sqlFinder = $this->getFinder();

        $files = $sqlFinder->files()
            ->in($this->sqlFilesDir)
            ->name($fileName)
        ;

        if ($files->count() !== 1) {
            throw new RuntimeException('cannot find File:' . $fileName);
        }

        foreach ($files as $file){
            return $file;
        }
    }

    /**
     * get applied files
     * @return Finder
     */
    public function getAppliedFiles()
    {
        $appliedFinder = $this->getFinder();

        $files = $appliedFinder->files()
            ->in($this->appliedFilesDir)
            ->name(self::PATTERN)
            ->sort($this->sort())
        ;

        return $files;
    }

    /**
     * get migration status
     * @return array Statuses with applied datetime and file name
     */
    public function getStatuses()
    {
        $files = $this->getSqlFiles();
        $appliedFiles = $this->getAppliedFiles();

        /**
         * is file applied or not
         * @param $file
         * @return bool if applied, return true.
         */
        $isApplied = function($file) use ($appliedFiles){
            foreach ($appliedFiles as $appliedFile) {
                if ($appliedFile->getFileName() === $file->getFileName()){
                    return true;
                }
            }
            return false;
        };

        $statuses = [];

        foreach($files as $file){
            $appliedAt = $isApplied($file)? date('Y-m-d H:i:s', $file->getMTime()): "";
            $statuses[] = new Status($appliedAt, $file);
        }

        return $statuses;
    }

    /**
     * get up candidates sql files
     */
    public function getUpCandidates()
    {
        $statuses = $this->getStatuses();

        foreach ($statuses as $status) {
            if ($status->appliedAt === "") {
				$candidates[] = $status;
            }
        }

        return $candidates;
    }

    /**
     * update database
     * @param $file sql file to apply
     */
    public function up($file)
    {
		if(!file_exists($file->getPathName())) {
            throw new RuntimeException($file->getPathName() . ' is not found.');
        }

		$this->db->exec($file->getPathName());

        $this->copyToAppliedDir($file);
    }

    /**
     * copy applied sql file to the applied directory.
     *
     * @param SplFileInfo $file
     */
    public function copyToAppliedDir($file)
    {
        if (false === @copy($file->getPathName(), $this->appliedFilesDir . '/' . $file->getFileName())) {
            throw new RuntimeException('cannot copy the sql file to applied directory. check the <info>'. $this->appliedFilesDir . '</info> directory.');
        }
    }
}
