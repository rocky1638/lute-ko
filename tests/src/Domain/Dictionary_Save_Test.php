<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Entity\TermTag;
use App\Entity\Term;
use App\Entity\Text;
use App\Domain\Dictionary;

// Tests to validate the Doctrine mappings.
final class Dictionary_Save_Test extends DatabaseTestBase
{

    private Dictionary $dictionary;
    private TermTag $tag;
    private Term $p;
    private Term $p2;

    public function childSetUp() {
        $this->dictionary = new Dictionary($this->term_repo);
        $this->load_languages();
    }


    public function test_add_updates_associated_textitems() {
        $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $this->make_text("Bonj.", "Je veux un tengo.", $this->french);

        DbHelpers::assertRecordcountEquals("textitems2", 16, 'sanity check');
        $sql = "select Ti2WoID, Ti2LgID, Ti2WordCount, Ti2Text from textitems2 where Ti2WoID <> 0 order by Ti2Order";
        DbHelpers::assertTableContains($sql, [], "No associations");

        $t = new Term($this->spanish, "tengo");
        $this->dictionary->add($t, true);
        $expected = [ "{$t->getID()}; 1; 1; tengo" ];
        DbHelpers::assertTableContains($sql, $expected, "associated textitems in spanish text only");

        $t = new Term($this->spanish, "un gato");
        $this->dictionary->add($t, true);
        $expected[] = "{$t->getID()}; 1; 2; un gato";
        DbHelpers::assertTableContains($sql, $expected, "associated multi-word term");
    }


    public function test_textitems_not_associated_until_flush() {
        $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $this->make_text("Bonj.", "Je veux un tengo.", $this->french);

        $sql = "select Ti2WoID, Ti2LgID, Ti2WordCount, Ti2Text from textitems2 where Ti2WoID <> 0 order by Ti2Order";
        DbHelpers::assertTableContains($sql, [], "No associations");

        $t1 = new Term($this->spanish, "tengo");
        $this->dictionary->add($t1, false);
        $t2 = new Term($this->spanish, "un gato");
        $this->dictionary->add($t2, false);

        DbHelpers::assertTableContains($sql, [], "No associations, not flushed");

        $this->dictionary->flush();

        $expected = [
            "{$t1->getID()}; 1; 1; tengo",
            "{$t2->getID()}; 1; 2; un gato"
        ];
        DbHelpers::assertTableContains($sql, $expected, "Now associated textitems (in spanish text only)");
    }


    // Production bug.
    public function test_save_multiword_term_multiple_times_is_ok() {
        $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);

        $t = new Term();
        $t->setLanguage($this->spanish);
        $t->setText("un gato");
        $this->dictionary->add($t, true);

        $sql = "select Ti2WoID, Ti2LgID, Ti2WordCount, Ti2Text from textitems2 where Ti2WoID <> 0 order by Ti2Order";
        $expected[] = "{$t->getID()}; 1; 2; un gato";
        DbHelpers::assertTableContains($sql, $expected, "associated multi-word term");

        // Update and resave
        $t->setStatus(5);
        $this->dictionary->add($t, true);
        DbHelpers::assertTableContains($sql, $expected, "still associated correctly");
    }

}
