<?php

class LernmarktplatzMaterial extends SimpleORMap {

    static public function findAll()
    {
        return self::findBySQL("draft = '0'");
    }

    static public function findMine($user_id = null)
    {
        $user_id || $user_id = $GLOBALS['user']->id;
        return self::findBySQL("INNER JOIN `lernmarktplatz_material_users` USING (material_id) WHERE `lernmarktplatz_material_users`.user_id = ? AND external_contact = '0' ORDER BY mkdate DESC", array($user_id));
    }

    static public function findByTag($tag_name)
    {
        self::fetchRemoteSearch($tag_name, true);
        $statement = DBManager::get()->prepare("
            SELECT lernmarktplatz_material.*
            FROM lernmarktplatz_material
                INNER JOIN lernmarktplatz_tags_material USING (material_id)
                INNER JOIN lernmarktplatz_tags USING (tag_hash)
            WHERE lernmarktplatz_tags.name = :tag
                AND lernmarktplatz_material.draft = '0'
            GROUP BY lernmarktplatz_material.material_id
            ORDER BY lernmarktplatz_material.mkdate DESC
        ");
        $statement->execute(array('tag' => $tag_name));
        $material_data = $statement->fetchAll(PDO::FETCH_ASSOC);
        $materials = array();
        foreach ($material_data as $data) {
            $materials[] = LernmarktplatzMaterial::buildExisting($data);
        }
        return $materials;
    }

    static public function findByText($text)
    {
        self::fetchRemoteSearch($text);
        $statement = DBManager::get()->prepare("
            SELECT lernmarktplatz_material.*
            FROM lernmarktplatz_material
                LEFT JOIN lernmarktplatz_tags_material USING (material_id)
                LEFT JOIN lernmarktplatz_tags USING (tag_hash)
            WHERE (
                    lernmarktplatz_material.name LIKE :text
                    OR description LIKE :text
                    OR short_description LIKE :text
                    OR lernmarktplatz_tags.name LIKE :text
                )
                AND lernmarktplatz_material.draft = '0'
            GROUP BY lernmarktplatz_material.material_id
            ORDER BY lernmarktplatz_material.mkdate DESC
        ");
        $statement->execute(array(
            'text' => "%".$text."%"
        ));
        $material_data = $statement->fetchAll(PDO::FETCH_ASSOC);
        $materials = array();
        foreach ($material_data as $data) {
            $materials[] = LernmarktplatzMaterial::buildExisting($data);
        }
        return $materials;
    }

    static public function findByTagHash($tag_hash)
    {
        $tag = LernmarktplatzTag::find($tag_hash);
        if ($tag) {
            self::fetchRemoteSearch($tag['name'], true);
        }
        return self::findBySQL("INNER JOIN lernmarktplatz_tags_material USING (material_id) WHERE lernmarktplatz_tags_material.tag_hash = ? AND draft = '0'", array($tag_hash));
    }

    static public function getFileDataPath() {
        return $GLOBALS['STUDIP_BASE_PATH'] . "/data/lehrmarktplatz";
    }

    static public function getImageFileDataPath() {
        return $GLOBALS['STUDIP_BASE_PATH'] . "/data/lehrmarktplatz_images";
    }

    /**
     * Searches on remote hosts for the text.
     * @param $text
     * @param bool|false $tag
     */
    static protected function fetchRemoteSearch($text, $tag = false) {
        $cache_name = "Lernmarktplatz_remote_searched_for_".md5($text)."_".($tag ? 1 : 0);
        $already_searched = (bool) StudipCacheFactory::getCache()->read($cache_name);
        if (!$already_searched) {
            $host = LernmarktplatzHost::findOneBySQL("index_server = '1' AND allowed_as_index_server = '1' ORDER BY RAND()");
            if ($host && !$host->isMe()) {
                $host->fetchRemoteSearch($text, $tag);
            }
            StudipCacheFactory::getCache()->read($cache_name, "1", 60);
        }
    }

    protected static function configure($config = array())
    {
        $config['db_table'] = 'lernmarktplatz_material';
        $config['belongs_to']['host'] = array(
            'class_name' => 'LernmarktplatzHost',
            'foreign_key' => 'host_id'
        );
        $config['has_many']['reviews'] = array(
            'class_name' => 'LernmarktplatzReview',
            'order_by' => 'ORDER BY mkdate DESC',
            'on_delete' => 'delete'
        );
        $config['has_many']['users'] = array(
            'class_name' => 'LernmarktplatzMaterialUser',
            'order_by' => 'ORDER BY position ASC'
        );
        $config['serialized_fields']['structure'] = 'JSONArrayObject';
        parent::configure($config);
    }

    public function delete()
    {
        $success = parent::delete();
        if ($success) {
            $this->setTopics(array());
            @unlink($this->getFilePath());
        }
        return $success;
    }

    public function getTopics()
    {
        $statement = DBManager::get()->prepare("
            SELECT lernmarktplatz_tags.*
            FROM lernmarktplatz_tags
                INNER JOIN lernmarktplatz_tags_material ON (lernmarktplatz_tags_material.tag_hash = lernmarktplatz_tags.tag_hash)
            WHERE lernmarktplatz_tags_material.material_id = :material_id
            ORDER BY lernmarktplatz_tags.name ASC
        ");
        $statement->execute(array('material_id' => $this->getId()));
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setTopics($tags) {
        $statement = DBManager::get()->prepare("
            DELETE FROM lernmarktplatz_tags_material
            WHERE material_id = :material_id
        ");
        $statement->execute(array('material_id' => $this->getId()));
        $insert_tag = DBManager::get()->prepare("
            INSERT IGNORE INTO lernmarktplatz_tags
            SET name = :tag,
                tag_hash = MD5(:tag)
        ");
        $add_tag = DBManager::get()->prepare("
            INSERT IGNORE INTO lernmarktplatz_tags_material
            SET tag_hash = MD5(:tag),
                material_id = :material_id
        ");
        foreach ($tags as $tag) {
            $insert_tag->execute(array(
                'tag' => $tag
            ));
            $add_tag->execute(array(
                'tag' => $tag,
                'material_id' => $this->getId()
            ));
        }
    }

    public function getFilePath()
    {
        if (!file_exists(self::getFileDataPath())) {
            mkdir(self::getFileDataPath());
        }
        if (!$this->getId()) {
            $this->setId($this->getNewId());
        }
        return self::getFileDataPath()."/".$this->getId();
    }

    public function getFrontImageFilePath()
    {
        if (!file_exists(self::getImageFileDataPath())) {
            mkdir(self::getImageFileDataPath());
        }
        if (!$this->getId()) {
            $this->setId($this->getNewId());
        }
        return self::getImageFileDataPath()."/".$this->getId();
    }

    public function getLogoURL($color = "blue")
    {
        if ($this['front_image_content_type']) {
            if ($this['host_id']) {
                return $this->host['url']."download_front_image/".$this['foreign_material_id'];
            } else {
                return URLHelper::getURL("plugins.php/lernmarktplatz/endpoints/download_front_image/".$this->getId());
            }
        } elseif ($this->isFolder()) {
            return Icon::create("folder-full", "clickable")->asImagePath();
        } elseif($this->isImage()) {
            return Icon::create("file-pic", "clickable")->asImagePath();
        } elseif($this->isPDF()) {
            return Icon::create("file-pdf", "clickable")->asImagePath();
        } elseif($this->isPresentation()) {
            return Icon::create("file-ppt", "clickable")->asImagePath();
        } elseif($this->isStudipQuestionnaire()) {
            return Icon::create("vote", "clickable")->asImagePath();
        } else {
            return Icon::create("file", "clickable")->asImagePath();
        }

    }

    public function isFolder()
    {
        return (bool) $this['structure'];
    }

    public function isImage()
    {
        return stripos($this['content_type'], "image") === 0;
    }

    public function isVideo()
    {
        return stripos($this['content_type'], "video") === 0;
    }

    public function isAudio()
    {
        return stripos($this['content_type'], "audio") === 0;
    }

    public function isPDF()
    {
        return $this['content_type'] === "application/pdf";
    }

    protected function getFileEnding()
    {
        return pathinfo($this["filename"], PATHINFO_EXTENSION);
    }

    public function isPresentation()
    {
        return in_array($this->getFileEnding(), array(
            "odp", "keynote", "ppt", "pptx"
        ));
    }

    public function isStudipQuestionnaire()
    {
        return $this['content_type'] === "application/json+studipquestionnaire";
    }

    public function addTag($tag_name) {
        $tag_hash = md5($tag_name);
        if (!LernmarktplatzTag::find($tag_hash)) {
            $tag = new LernmarktplatzTag();
            $tag->setId($tag_hash);
            $tag['name'] = $tag_name;
            $tag->store();
        }
        $statement = DBManager::get()->prepare("
            INSERT IGNORE INTO lernmarktplatz_tags_material
            SET tag_hash = :tag_hash,
                material_id = :material_id
        ");
        return $statement->execute(array(
            'tag_hash' => $tag_hash,
            'material_id' => $this->getId()
        ));
    }

    public function pushDataToIndexServers($delete = false)
    {
        $myHost = LernmarktplatzHost::thisOne();
        $data = array();
        $data['host'] = array(
            'name' => $myHost['name'],
            'url' => $myHost['url'],
            'public_key' => $myHost['public_key']
        );
        $data['data'] = $this->toArray();
        $data['data']['foreign_material_id'] = $data['data']['material_id'];
        unset($data['data']['material_id']);
        unset($data['data']['id']);
        unset($data['data']['user_id']);
        unset($data['data']['host_id']);
        $data['users'] = array();
        foreach ($this->users as $materialuser) {
            $data['users'][] = $materialuser->getJSON();
        }
        $data['topics'] = array();
        foreach ($this->getTopics() as $tag) {
            if ($tag['name']) {
                $data['topics'][] = $tag['name'];
            }
        }
        if ($delete) {
            $data['delete_material'] = 1;
        }

        foreach (LernmarktplatzHost::findBySQL("index_server = '1' AND allowed_as_index_server = '1' ") as $index_server) {
            if (!$index_server->isMe()) {
                echo " push ";
                $index_server->pushDataToEndpoint("push_data", $data);
            }
        }
    }

    public function fetchData()
    {
        if ($this['host_id']) {
            $host = new LernmarktplatzHost($this['host_id']);
            if ($host) {
                $data = $host->fetchItemData($this['foreign_material_id']);

                if (!$data) {
                    return false;
                }

                if ($data['deleted']) {
                    return "deleted";
                }

                //user:
                $old_user_ids = $this->users->pluck("user_id");
                $current_user_ids = [];
                foreach ($data['users'] as $index => $userdata) {
                    $userhost = LernmarktplatzHost::findOneBySQL("url = ?", [$userdata['host_url']]);
                    if ($userhost->isMe()) {
                        $user = User::find($userdata['user_id']);
                        $materialuser = LernmarktplatzMaterialUser::findOneBySQL("material_id = ? AND user_id = ? AND external_contact = '0'", [$this->getId(), $user->getId()]);
                        if (!$materialuser) {
                            $materialuser = new LernmarktplatzMaterialUser();
                            $materialuser['user_id'] = $user->getId();
                            $materialuser['material_id'] = $this->getId();
                            $materialuser['external_contact'] = 0;
                        }
                        $materialuser['position'] = $index + 1;
                        $materialuser->store();
                        $current_user_ids[] = $user->getId();
                    } else {
                        $user = LernmarktplatzUser::findOneBySQL("foreign_user_id = ? AND host_id = ?", [$userdata['user_id'], $userhost->getId()]);
                        if (!$user) {
                            $user = new LernmarktplatzUser();
                            $user['foreign_user_id'] = $userdata['user_id'];
                            $user['host_id'] = $userhost->getId();
                        }
                        $user['name'] = $userdata['name'];
                        $user['avatar'] = $userdata['avatar'] ?: null;
                        $user['description'] = $userdata['description'] ?: null;
                        $user->store();

                        $materialuser = LernmarktplatzMaterialUser::findOneBySQL("material_id = ? AND user_id = ? AND external_contact = '1'", [$this->getId(), $user->getId()]);
                        if (!$materialuser) {
                            $materialuser = new LernmarktplatzMaterialUser();
                            $materialuser['user_id'] = $user->getId();
                            $materialuser['material_id'] = $this->getId();
                            $materialuser['external_contact'] = 1;

                        }
                        $materialuser['position'] = $index + 1;
                        $materialuser->store();
                        $current_user_ids[] = $user->getId();
                    }
                }
                foreach (array_diff($old_user_ids, $current_user_ids) as $deletable_user_id) {
                    LernmarktplatzMaterialUser::deleteBySQL("material_id = ? AND user_id = ?", [$this->getId(), $deletable_user_id]);
                }



                //material:
                $material_data = $data['data'];
                unset($material_data['material_id']);
                unset($material_data['user_id']);
                unset($material_data['mkdate']);
                $this->setData($material_data);
                $this->store();

                //topics:
                $this->setTopics($data['topics']);

                foreach ((array) $data['reviews'] as $review_data) {
                    $currenthost = LernmarktplatzHost::findOneByUrl(trim($review_data['host']['url']));
                    if (!$currenthost) {
                        $currenthost = new LernmarktplatzHost();
                        $currenthost['url'] = trim($review_data['host']['url']);
                        $currenthost['last_updated'] = time();
                        $currenthost->fetchPublicKey();
                        if ($currenthost['public_key']) {
                            $currenthost->store();
                        }
                    }
                    if ($currenthost && $currenthost['public_key'] && !$currenthost->isMe()) {
                        $review = LernmarktplatzReview::findOneBySQL("foreign_review_id = ? AND host_id = ?", array(
                            $review_data['foreign_review_id'],
                            $currenthost->getId()
                        ));
                        if (!$review) {
                            $review = new LernmarktplatzReview();
                            $review['foreign_review_id'] = $review_data['foreign_review_id'];
                            $review['material_id'] = $this->getId();
                            $review['host_id'] = $currenthost->getId();
                        }
                        $review['review'] = $review_data['review'];
                        $review['rating'] = $review_data['rating'];
                        if ($review_data['chdate']) {
                            $review['chdate'] = $review_data['chdate'];
                        }
                        if ($review_data['mkdate']) {
                            $review['mkdate'] = $review_data['mkdate'];
                        }

                        $user = LernmarktplatzUser::findOneBySQL("foreign_user_id = ? AND host_id = ?", array($review_data['user']['user_id'], $currenthost->getId()));
                        if (!$user) {
                            $user = new LernmarktplatzUser();
                            $user['foreign_user_id'] = $review_data['user']['user_id'];
                            $user['host_id'] = $currenthost->getId();
                        }
                        $user['name'] = $review_data['user']['name'];
                        $user['avatar'] = $review_data['user']['avatar'] ?: null;
                        $user['description'] = $review_data['user']['description'] ?: null;
                        $user->store();

                        $review['user_id'] = $user->getId();
                        $review->store();
                    }
                }
            }
        }
        return true;
    }

    public function calculateRating() {
        $rating = 0;
        $factors = 0;
        foreach ($this->reviews as $review) {
            $age = time() - $review['chdate'];
            $factor = (pi() - 2 * atan($age / (86400 * 180))) / pi();
            $rating += $review['rating'] * $factor * 2;
            $factors += $factor;
        }
        if ($factors > 0) {
            $rating /= $factors;
        } else {
            return $rating = null;
        }
        return $rating;
    }

    public function isMine()
    {
        $user = LernmarktplatzMaterialUser::findOneBySQL("material_id = ? AND external_contact = '0' AND user_id = ?", [$this->getId(), $GLOBALS['user']->id]);
        return $user ? true : false;
    }
}
