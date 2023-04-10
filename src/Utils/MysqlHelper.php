<?php

namespace App\Utils;

require_once __DIR__ . '/../../db/mysql/lib/mysql_migrator.php';

use App\Entity\Language;
use App\Repository\LanguageRepository;
use App\Entity\Text;
use App\Repository\TextRepository;
use App\Repository\BookRepository;
use App\Entity\Term;
use App\Domain\Dictionary;
use App\Domain\BookBinder;
use App\Domain\JapaneseParser;

// Class for namespacing only.
class MysqlHelper {

    // Returns [ server, user, pass, dbname ]
    public static function getParams() {
        $getOrThrow = function($key, $throwIfMissing = true) {
            if (! isset($_ENV[$key]))
                throw new \Exception("Missing ENV key $key");
            $ret = $_ENV[$key];
            if ($throwIfMissing && ($ret == null || $ret == ''))
                throw new \Exception("Empty ENV key $key");
            return $ret;
        };

        return [
            $getOrThrow('DB_HOSTNAME'),
            $getOrThrow('DB_USER'),
            $getOrThrow('DB_PASSWORD', false),
            $getOrThrow('DB_DATABASE')
        ];
    }

    /**
     * Open MySQL connection using the environment settings.  Public static for testing.
     */
    public static function getConn() {
        $user = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASSWORD'];
        $host = $_ENV['DB_HOSTNAME'];
        $dbname = $_ENV['DB_DATABASE'];
        $d = "mysql:host={$host};dbname={$dbname}";

        $dbh = new \PDO($d, $user, $password);
        $dbh->query("SET NAMES 'utf8'");
        $dbh->query("SET SESSION sql_mode = ''");
        return $dbh;
    }

    /**
     * Verify the environment connection params.
     * Throws exception if the values are no good.
     */
    private static function verifyConnectionParams()
    {
        [ $server, $userid, $passwd, $db ] = MysqlHelper::getParams();
        $conn = @mysqli_connect($server, $userid, $passwd);
        if (!$conn) {
            $errmsg = mysqli_connect_error();
            $errnum = mysqli_connect_errno();
            $msg = "{$errmsg} ({$errnum})";
            throw new \Exception($msg);
        }
        mysqli_close($conn);
    }

    private static function getMigrator($showlogging = false) {
        [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();

        $dir = __DIR__ . '/../../db/mysql/migrations';
        $repdir = __DIR__ . '/../../db/mysql/migrations_repeatable';
        $migration = new \MysqlMigrator($dir, $repdir, $server, $dbname, $userid, $passwd, $showlogging);
        return $migration;
    }


    private static function databaseExists() {
        $conn = null;
        try {
            $conn = @mysqli_connect(...MysqlHelper::getParams());
            mysqli_close($conn);
            return true;
        }
        catch (\Exception $e) {
            if (mysqli_connect_errno() == 1049) {
                return false;
            }

            // Otherwise, it was some other weird error ...
            $errmsg = mysqli_connect_error();
            $errnum = mysqli_connect_errno();
            $msg = "{$errmsg} ({$errnum})";
            throw new \Exception($msg);
        }
    }


    private static function createBlankDatabase() {
        [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();
        $conn = @mysqli_connect($server, $userid, $passwd);
        $sql = "CREATE DATABASE `{$dbname}` 
            DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci";
        $result = $conn->query($sql);
        mysqli_close($conn);
        if (! $result) {
            throw new \Exception("Unable to create db $dbname");
        }

        // Verify
        try {
            $conn = MysqlHelper::getConn();
        }
        catch (\Exception $e) {
            $msg = "Unable to connect to newly created db.";
            throw new \Exception($msg);
        }
    }


    /**
     * Used by public/index.php to initiate and migrate the database.
     * 
     * Feels kind of messy, not sure where this belongs, but it will do
     * until proven poor.  ... only tested manually.
     *
     * Returns [ messages, error string ]
     */
    public static function doSetup(): array {

        $messages = [];
        $error = null;
        $newdbcreated = false;
        try {
            MysqlHelper::verifyConnectionParams();

            $dbexists = MysqlHelper::databaseExists();

            if ($dbexists && MysqlHelper::isLearningWithTextsDb()) {
                [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();
                $args = [
                    'dbname' => $dbname,
                    'username' => $userid
                ];
                $error = MysqlHelper::renderError('will_not_migrate_lwt_automatically.html.twig', $args);
                return [ $messages, $error ];
            }

            if (! $dbexists) {
                MysqlHelper::createBlankDatabase();
                MysqlHelper::installBaseline();
                $newdbcreated = true;
                $messages[] = 'New database created.';
            }

            if (MysqlHelper::hasPendingMigrations()) {
                MysqlHelper::runMigrations();
                if (! $newdbcreated) {
                    $messages[] = 'Database updated.';
                }
            }
        }
        catch (\Exception $e) {
            $args = ['errors' => [ $e->getMessage() ]];
            $error = MysqlHelper::renderError('fatal_error.html.twig', $args);
        }

        return [ $messages, $error ];
    }

    private static function renderError($name, $args = []): string {
        // ref https://twig.symfony.com/doc/2.x/api.html
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../templates/errors');
        $twig = new \Twig\Environment($loader);
        $template = $twig->load($name);
        return $template->render($args);
    }

    public static function hasPendingMigrations() {
        $migration = MysqlHelper::getMigrator();
        return count($migration->get_pending()) > 0;
    }

    public static function runMigrations($showlogging = false) {
        [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();
        $migration = MysqlHelper::getMigrator($showlogging);
        $migration->exec("ALTER DATABASE `{$dbname}` CHARACTER SET utf8 COLLATE utf8_general_ci");
        $migration->process();
    }

    public static function installBaseline() {
        $files = [
            'baseline_schema.sql',
            'reference_data.sql'
        ];
        foreach ($files as $f) {
            $basepath = __DIR__ . '/../../db/mysql/baseline/';
            MysqlHelper::process_file($basepath . $f);
        }
    }

    private static function process_file($file) {
        $conn = MysqlHelper::getConn();
        $commands = file_get_contents($file);
        $conn->query($commands);
    }

    public static function isLuteDemo() {
        [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();
        return ($dbname == 'lute_demo');
    }

    public static function isEmptyDemo() {
        if (! MysqlHelper::isLuteDemo())
            return false;

        $conn = MysqlHelper::getConn();
        $check = $conn
               ->query('select count(*) as c from Languages')
               ->fetch(\PDO::FETCH_ASSOC);
        $c = intval($check['c']);
        return $c == 0;
    }

    public static function isLearningWithTextsDb() {
        [ $server, $userid, $passwd, $dbname ] = MysqlHelper::getParams();
        $sql = "select count(*) as c from information_schema.tables
          where table_schema = '{$dbname}'
          and table_name = '_lwtgeneral'";
        $conn = MysqlHelper::getConn();
        $check = $conn
               ->query($sql)
               ->fetch(\PDO::FETCH_ASSOC);
        $c = intval($check['c']);
        return $c == 1;
    }


    public static function loadDemoData(
        LanguageRepository $lang_repo,
        BookRepository $book_repo,
        Dictionary $dictionary
    ) {
        $e = Language::makeEnglish();
        $f = Language::makeFrench();
        $s = Language::makeSpanish();
        $g = Language::makeGerman();
        $cc = Language::makeClassicalChinese();

        $langs = [ $e, $f, $s, $g, $cc ];
        $files = [
            'tutorial.txt',
            'tutorial_follow_up.txt',
            'es_aladino.txt',
            'fr_goldilocks.txt',
            'de_Stadtmusikanten.txt',
            'cc_demo.txt',
        ];

        if (JapaneseParser::MeCab_installed()) {
            $langs[] = Language::makeJapanese();
            $files[] = 'jp_kitakaze_to_taiyou.txt';
        }

        $langmap = [];
        foreach ($langs as $lang) {
            $lang_repo->save($lang, true);
            $langmap[ $lang->getLgName() ] = $lang;
        }

        $conn = MysqlHelper::getConn();
        $conn->query('ALTER TABLE texts AUTO_INCREMENT = 1');

        foreach ($files as $f) {
            $fname = $f;
            $basepath = __DIR__ . '/../../demo/';
            $fullcontent = file_get_contents($basepath . $fname);
            $content = preg_replace('/#.*\n/u', '', $fullcontent);

            preg_match('/language:\s*(.*)\n/u', $fullcontent, $matches);
            $lang = $langmap[$matches[1]];

            preg_match('/title:\s*(.*)\n/u', $fullcontent, $matches);
            $title = $matches[1];

            $b = BookBinder::makeBook($title, $lang, $content);
            $book_repo->save($b, true);
        }

        $term = new Term();
        $term->setLanguage($e);
        $zws = mb_chr(0x200B);
        $term->setText("your{$zws} {$zws}local{$zws} {$zws}environment{$zws} {$zws}file");
        $term->setStatus(3);
        $term->setTranslation("This is \".env\", your personal file in the project root folder :-)");
        $dictionary->add($term, true);
    }

}

?>