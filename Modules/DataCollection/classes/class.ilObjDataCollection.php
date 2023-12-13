<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/


declare(strict_types=1);

class ilObjDataCollection extends ilObject2
{
    private bool $is_online = false;
    private bool $rating = false;
    private bool $approval = false;
    private bool $public_notes = false;
    private bool $notification = false;

    protected function initType(): void
    {
        $this->type = "dcl";
    }

    protected function doRead(): void
    {
        $result = $this->db->query("SELECT * FROM il_dcl_data WHERE id = " . $this->db->quote($this->getId(), "integer"));

        $data = $this->db->fetchObject($result);
        if ($data) {
            $this->setOnline((bool)$data->is_online);
            $this->setRating((bool)$data->rating);
            $this->setApproval((bool)$data->approval);
            $this->setPublicNotes((bool)$data->public_notes);
            $this->setNotification((bool)$data->notification);
        }
    }

    protected function doCreate(bool $clone_mode = false): void
    {
        $this->log->write('doCreate');

        if (!$clone_mode) {
            //Create Main Table - The title of the table is per default the title of the data collection object
            $main_table = ilDclCache::getTableCache();
            $main_table->setObjId($this->getId());
            $main_table->setTitle($this->getTitle());
            $main_table->setAddPerm(true);
            $main_table->setEditPerm(true);
            $main_table->setDeletePerm(false);
            $main_table->setDeleteByOwner(true);
            $main_table->setEditByOwner(true);
            $main_table->setLimited(false);
            $main_table->setIsVisible(true);
            $main_table->doCreate();
        }

        $this->db->insert(
            "il_dcl_data",
            [
                "id" => ["integer", $this->getId()],
                "is_online" => ["integer", (int) $this->getOnline()],
                "rating" => ["integer", (int) $this->getRating()],
                "public_notes" => ["integer", (int) $this->getPublicNotes()],
                "approval" => ["integer", (int) $this->getApproval()],
                "notification" => ["integer", (int) $this->getNotification()],
            ]
        );
    }

    protected function doDelete(): void
    {
        foreach ($this->getTables() as $table) {
            $table->doDelete(false, true);
        }

        $query = "DELETE FROM il_dcl_data WHERE id = " . $this->db->quote($this->getId(), "integer");
        $this->db->manipulate($query);
    }

    protected function doUpdate(): void
    {
        $this->db->update(
            "il_dcl_data",
            [
                "id" => ["integer", $this->getId()],
                "is_online" => ["integer", (int) $this->getOnline()],
                "rating" => ["integer", (int) $this->getRating()],
                "public_notes" => ["integer", (int) $this->getPublicNotes()],
                "approval" => ["integer", (int) $this->getApproval()],
                "notification" => ["integer", (int) $this->getNotification()],
            ],
            [
                "id" => ["integer", $this->getId()],
            ]
        );
    }

    public function sendNotification($a_action, $a_table_id, $a_record_id = null): void
    {
        global $DIC;

        $http = $DIC->http();
        $refinery = $DIC->refinery();
        $user = $DIC->user();

        $ref_id = $http->wrapper()->query()->retrieve('ref_id', $refinery->kindlyTo()->int());

        // If coming from trash, never send notifications and don't load dcl Object
        if ($this->getRefId() === SYSTEM_FOLDER_ID) {
            return;
        }

        if ($this->getNotification() != true) {
            return;
        }
        $obj_table = ilDclCache::getTableCache($a_table_id);

        // recipients
        $users = ilNotification::getNotificationsForObject(
            ilNotification::TYPE_DATA_COLLECTION,
            $this->getId(),
            1
        );
        if (!count($users)) {
            return;
        }

        ilNotification::updateNotificationTime(ilNotification::TYPE_DATA_COLLECTION, $this->getId(), $users);

        $link = ilLink::_getLink($this->getRefId());

        // prepare mail content
        // use language of recipient to compose message

        // send mails
        foreach (array_unique($users) as $idx => $user_id) {
            // the user responsible for the action should not be notified
            $record = ilDclCache::getRecordCache($a_record_id);
            $ilDclTable = new ilDclTable($record->getTableId());

            if ($user_id != $user->getId() && $ilDclTable->hasPermissionToViewRecord($ref_id, $record, $user_id)) {
                // use language of recipient to compose message
                $ulng = ilLanguageFactory::_getLanguageOfUser($user_id);
                $ulng->loadLanguageModule('dcl');

                $subject = sprintf($ulng->txt('dcl_change_notification_subject'), $this->getTitle());
                // update/delete
                $message = $ulng->txt("dcl_hello") . " " . ilObjUser::_lookupFullname($user_id) . ",\n\n";
                $message .= $ulng->txt('dcl_change_notification_dcl_' . $a_action) . ":\n\n";
                $message .= $ulng->txt('obj_dcl') . ": " . $this->getTitle() . "\n\n";
                $message .= $ulng->txt('dcl_table') . ": " . $obj_table->getTitle() . "\n\n";
                $message .= $ulng->txt('dcl_record') . ":\n";
                $message .= "------------------------------------\n";
                if ($a_record_id) {
                    if (!$record->getTableId()) {
                        $record->setTableId($a_table_id);
                    }
                    //					$message .= $ulng->txt('dcl_record_id').": ".$a_record_id.":\n";
                    $t = "";

                    if ($tableview_id = $record->getTable()->getFirstTableViewId($this->getRefId(), $user_id)) {
                        $visible_fields = ilDclTableView::find($tableview_id)->getVisibleFields();
                        if (empty($visible_fields)) {
                            continue;
                        }
                        /** @var ilDclBaseFieldModel $field */
                        foreach ($visible_fields as $field) {
                            $value = null;
                            if ($field->isStandardField()) {
                                $value = $record->getStandardFieldPlainText($field->getId());
                            } elseif ($record_field = $record->getRecordField((int)$field->getId())) {
                                $value = $record_field->getPlainText();
                            }

                            if ($value) {
                                $t .= $field->getTitle() . ": " . $value . "\n";
                            }
                        }
                    }
                    $message .= $this->prepareMessageText($t);
                }
                $message .= "------------------------------------\n";
                $message .= $ulng->txt('dcl_changed_by') . ": " . $user->getFullname() . " " . ilUserUtil::getNamePresentation($user->getId())
                    . "\n\n";
                $message .= $ulng->txt('dcl_change_notification_link') . ": " . $link . "\n\n";

                $message .= $ulng->txt('dcl_change_why_you_receive_this_email');

                $mail_obj = new ilMail(ANONYMOUS_USER_ID);
                $mail_obj->appendInstallationSignature(true);
                $mail_obj->enqueue(ilObjUser::_lookupLogin($user_id), "", "", $subject, $message, []);
            } else {
                unset($users[$idx]);
            }
        }
    }

    /**
     * for users with write access, return id of table with the lowest sorting
     * for users with no write access, return id of table with the lowest sorting, which is visible
     */
    public function getFirstVisibleTableId(): int
    {
        $this->db->setLimit(1);
        $only_visible = ilObjDataCollectionAccess::hasWriteAccess($this->ref_id) ? '' : ' AND is_visible = 1 ';
        $result = $this->db->query(
            'SELECT id FROM il_dcl_table
                    WHERE obj_id = ' . $this->db->quote($this->getId(), 'integer') .
                    $only_visible . ' ORDER BY -table_order DESC'
        ); //"-table_order DESC" is ASC with NULL last

        // if there's no visible table, fetch first one not visible
        // this is to avoid confusion, since the default of a table after creation is not visible
        if (!$result->numRows() && $only_visible) {
            $this->db->setLimit(1);
            $result = $this->db->query(
                'SELECT id FROM il_dcl_table
                        WHERE obj_id = ' . $this->db->quote($this->getId(), 'integer') . ' ORDER BY -table_order DESC '
            );
        }

        return $this->db->fetchObject($result)->id;
    }

    public function reorderTables(array $table_order): void
    {
        if ($table_order) {
            $order = 10;
            foreach ($table_order as $title) {
                $table_id = ilDclTable::_getTableIdByTitle($title, $this->getId());
                $table = ilDclCache::getTableCache($table_id);
                $table->setOrder($order);
                $table->doUpdate();
                $order += 10;
            }
        }
    }

    /**
     * Clone DCL
     * @param ilObject2 $new_obj
     * @param int       $a_target_id ref_id
     * @param int|null  $a_copy_id
     * @return void
     */
    protected function doCloneObject(ilObject2 $new_obj, int $a_target_id, ?int $a_copy_id = null): void
    {
        assert($new_obj instanceof ilObjDataCollection);
        //copy online status if object is not the root copy object
        $cp_options = ilCopyWizardOptions::_getInstance($a_copy_id);

        if (!$cp_options->isRootNode($this->getRefId())) {
            $new_obj->setOnline($this->getOnline());
        }

        $new_obj->cloneStructure($this->getRefId());
    }

    /**
     * Attention only use this for objects who have not yet been created (use like: $x = new ilObjDataCollection; $x->cloneStructure($id))
     * @param int $original_id The original ID of the dataselection you want to clone it's structure
     */
    public function cloneStructure(int $original_id): void
    {
        $original = new ilObjDataCollection($original_id);

        $this->setApproval($original->getApproval());
        $this->setNotification($original->getNotification());
        $this->setPublicNotes($original->getPublicNotes());
        $this->setRating($original->getRating());

        // delete old tables.
        foreach ($this->getTables() as $table) {
            $table->doDelete();
        }

        // add new tables.
        foreach ($original->getTables() as $table) {
            $new_table = new ilDclTable();
            $new_table->setObjId($this->getId());
            $new_table->cloneStructure($table);
        }

        // mandatory for all cloning functions
        ilDclCache::setCloneOf($original_id, $this->getId(), ilDclCache::TYPE_DATACOLLECTION);

        foreach ($this->getTables() as $table) {
            $table->afterClone();
        }
    }

    /**
     * setOnline
     */
    public function setOnline(bool $a_val): void
    {
        $this->is_online = $a_val;
    }

    /**
     * getOnline
     */
    public function getOnline(): bool
    {
        return $this->is_online;
    }

    public function setRating(bool $a_val): void
    {
        $this->rating = $a_val;
    }

    public function getRating(): bool
    {
        return $this->rating;
    }

    public function setPublicNotes(bool $a_val)
    {
        $this->public_notes = $a_val;
    }

    public function getPublicNotes(): bool
    {
        return $this->public_notes;
    }

    public function setApproval(bool $a_val): void
    {
        $this->approval = $a_val;
    }

    public function getApproval(): bool
    {
        return $this->approval;
    }

    public function setNotification(bool $a_val): void
    {
        $this->notification = $a_val;
    }

    public function getNotification(): bool
    {
        return $this->notification;
    }

    /**
     * @param $ref int the reference id of the datacollection object to check.
     * @return bool whether or not the current user has admin/write access to the referenced datacollection
     * @deprecated
     */
    public static function _hasWriteAccess(int $ref): bool
    {
        return ilObjDataCollectionAccess::hasWriteAccess($ref);
    }

    /**
     * @param $ref int the reference id of the datacollection object to check.
     * @return bool whether or not the current user has add/edit_entry access to the referenced datacollection
     * @deprecated
     */
    public static function _hasReadAccess(int $ref): bool
    {
        return ilObjDataCollectionAccess::hasReadAccess($ref);
    }

    /**
     * @return ilDclTable[] Returns an array of tables of this collection with ids of the tables as keys.
     */
    public function getTables(): array
    {
        $query = "SELECT id FROM il_dcl_table WHERE obj_id = " . $this->db->quote($this->getId(), "integer") .
            " ORDER BY -table_order DESC";
        $set = $this->db->query($query);
        $tables = [];

        while ($rec = $this->db->fetchAssoc($set)) {
            $tables[$rec['id']] = ilDclCache::getTableCache($rec['id']);
        }

        return $tables;
    }

    public function getTableById(int $table_id): ilDclTable
    {
        return ilDclCache::getTableCache($table_id);
    }

    public function getVisibleTables(): array
    {
        $tables = [];
        foreach ($this->getTables() as $table) {
            if ($table->getIsVisible() && $table->getVisibleTableViews($this->ref_id)) {
                $tables[$table->getId()] = $table;
            }
        }

        return $tables;
    }

    /**
     * Checks if a DataCollection has a table with a given title
     */
    public static function _hasTableByTitle(string $title, int $obj_id): bool
    {
        global $DIC;
        $ilDB = $DIC['ilDB'];
        $result = $ilDB->query(
            'SELECT * FROM il_dcl_table WHERE obj_id = ' . $ilDB->quote($obj_id, 'integer') . ' AND title = '
            . $ilDB->quote($title, 'text')
        );

        return (bool) $ilDB->numRows($result);
    }

    public function getStyleSheetId(): int
    {
        return 0;
    }

    public function prepareMessageText(string $body): string
    {
        if (preg_match_all('/<.*?br.*?>/', $body, $matches)) {
            $matches = array_unique($matches[0]);
            $brNewLineMatches = array_map(static function ($match): string {
                return $match . "\n";
            }, $matches);

            //Remove carriage return to guarantee all new line can be properly found
            $body = str_replace("\r", '', $body);
            //Replace occurrence of <br> + \n with a single \n
            $body = str_replace($brNewLineMatches, "\n", $body);
            //Replace additional <br> with a \”
            $body = str_replace($matches, "\n", $body);
            //Revert removal of carriage return
            return str_replace("\n", "\r\n", $body);
        }
        return $body;
    }
}
