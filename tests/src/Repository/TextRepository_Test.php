<?php declare(strict_types=1);

require_once __DIR__ . '/../../db_helpers.php';
require_once __DIR__ . '/../../DatabaseTestBase.php';

use App\Entity\Text;

final class TextRepository_Test extends DatabaseTestBase
{

    private Text $text;
    
    public function childSetUp(): void
    {
        // Set up db.
        $this->load_languages();
        // make_text auto-parses the text.
        $t = $this->make_text("Hola.", "Hola tengo un gato.", $this->spanish);
        $this->text = $t;

        DbHelpers::assertRecordcountEquals("sentences", 1, 'setup sentences');
        DbHelpers::assertRecordcountEquals("texts", 1, 'setup texts');
    }

    /**
     * @group reworkparsing
     */
    public function test_saving_Text_entity_makes_sentences()
    {
        $t = $this->make_text("Hola.", "Tengo un gato. Un perro.", $this->spanish);
        $sql = "select TokSentenceNumber, TokOrder, TokIsWord, TokText from texttokens where TokTxID = {$t->getID()} order by TokOrder";

        $sql = "select SeID, SeTxID, SeOrder, SeText from sentences where SeTxID = {$t->getID()}";
        $expected = [
            "2; 2; 1; /Tengo/ /un/ /gato/./",
            "3; 2; 2; /Un/ /perro/./"
        ];
        DbHelpers::assertTableContains($sql, $expected);
    }

    /**
     * @group textsent
     */
    public function test_parsing_Text_replaces_existing_sentences()
    {
        $t = $this->text;

        $sqlsent = "select SeID, SeTxID, SeText from sentences";

        DbHelpers::assertTableContains($sqlsent, [ "1; 1; /Hola/ /tengo/ /un/ /gato/./" ], 'sentences');

        $t->setText("Hola tengo un perro.");
        $this->text_repo->save($t, true);
        // Saving a text automatically re-parses it.

        DbHelpers::assertTableContains($sqlsent, [ "1; 1; /Hola/ /tengo/ /un/ /perro/./" ], "sent ID _not_ incremented :-P");
    }

    public function test_removing_Text_removes_sentences()
    {
        $t = $this->text;
        $this->text_repo->remove($t, true);

        DbHelpers::assertRecordcountEquals('sentences', 0, 'after');
        DbHelpers::assertRecordcountEquals("texts", 0, 'after');
    }


    public function test_archiving_Text_leaves_sentences()
    {
        $t = $this->text;
        $t->setArchived(true);
        $this->text_repo->save($t, true);

        DbHelpers::assertRecordcountEquals('sentences', 1, 'after');
        DbHelpers::assertRecordcountEquals("texts", 1, 'after');
    }

}
