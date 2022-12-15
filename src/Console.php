<?php 
namespace OneFileManager;


/**
 * Console to run commands
 */
class Console
{
    // OneFileManager\FileManager instance that this object belongs to
    public $manager;

    // OneFileManager\Connector instance
    private $connector;

    // Database connection
    private $connection;

    // Sql Clauses
    private $clauses;
    private $raw_clauses;

    // Input data
    private $input_data;


    /**
     * summary
     */
    public function __construct(FileManager $manager)
    {
        $this->manager = $manager;

        $this->connector = $this->manager->getConnector();
        $this->connection = $this->connector->getConnection();

        $this->clauses = [];
        $this->raw_clauses = [];
        if ($this->connector->getOption("user_id")) {
            $this->clauses["user_id"] = $this->connector->getOption("user_id");
        }

        $this->outdata = new \stdClass;
    }

    /**
     * Set input data
     * @param mixed $data Input data, generally stdClass instance
     */
    public function setInputData($data)
    {
        $this->input_data = $data;
        return $this;
    }


    /**
     * Get input data
     * @return mixed Input data
     */
    public function getInputData()
    {
        return $this->input_data;
    }


    /**
     * Run SQL query
     * @param  [type] $clauses [description]
     * @return [type]          [description]
     */
    public function runsql($sql, $clauses = null, $raw_clauses = null)
    {
        $params = [];

        $clauses_sql = "";

        if ($clauses || $raw_clauses) {
            $sql_arr = [];

            if ($clauses) {
                foreach ($clauses as $key => $value) {
                    $sql_arr[] = $key . " = ? ";
                    $params[] = $value;
                }
            }

            if ($raw_clauses) {
                foreach ($raw_clauses as $rc) {
                    $sql_arr[] = $rc;
                }
            }

            $clauses_sql = " WHERE " . implode(" AND ", $sql_arr);
        }

        $sql = str_replace("{clauses_sql}", $clauses_sql, $sql);

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }


    /**
     * Add a new record to file data table
     * @param array $data 
     */
    public function addDBRecord($data = array()) 
    {
        $data = array_merge([
            "id" => null,
            "user_id" => $this->connector->getOption("user_id"),
            "title" => "",
            "info" => "",
            "filename" => "",
            "filesize" => 0,
            "date" => date("Y-m-d H:i:s")
        ], $data);

        $sql = "INSERT INTO ".$this->connector->getOption("table_name")." (id, user_id, title, info, filename, filesize, date) VALUES "
             . "(?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute(array_values($data));

        return $this->getDBRecord($this->connection->lastInsertId());
    }


    /**
     * Get file data from files data table
     * @param  integer $fileid ID of the file
     * @return \PDO         $stmt
     */
    public function getDBRecord($fileid)
    {
        $stmt = $this->connection->prepare("SELECT * FROM ".$this->connector->getOption("table_name")." WHERE id = ?");
        $stmt->execute(array($fileid));

        return $stmt->rowCount() == 1 ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
    }


    /**
     * Get total used storage size
     * @return void 
     */
    public function getUsedStorageSize()
    {
        $sql = "SELECT SUM(filesize) AS total FROM ".$this->connector->getOption("table_name")." {clauses_sql};";
        $clauses = $this->clauses;
        $stmt = $this->runsql($sql, $clauses);

        return $stmt->fetchColumn();
    }


    /**
     * Command: init
     * @return void        
     */
    public function init()
    {
        // Get total amount of files
        $sql = "SELECT COUNT(id) AS total FROM ".$this->connector->getOption("table_name")." {clauses_sql};";
        $clauses = $this->clauses;
        $stmt = $this->runsql($sql, $clauses);

        $this->outdata->hasmore = $stmt->fetchColumn() > 0 ? true : false;
        $this->outdata->allow = $this->manager->getOption("allow");
        $this->outdata->deny = $this->manager->getOption("deny");
        $this->outdata->queue_size = $this->manager->getOption("queue_size");
        $this->outdata->max_file_size = $this->manager->getOption("max_file_size");
        $this->outdata->max_storage_size = $this->manager->getOption("max_storage_size");
        $this->outdata->used_storage_size = $this->getUsedStorageSize();

        return Common::success($this->outdata);
    }


    /**
     * Command: info
     * Get info about the storage
     * @return void        
     */
    public function info()
    {
        // Get total amount of files
        $sql = "SELECT COUNT(id) AS total FROM ".$this->connector->getOption("table_name")." {clauses_sql};";
        $clauses = $this->clauses;
        $stmt = $this->runsql($sql, $clauses);

        $this->outdata->total_files = $stmt->fetchColumn();

        $this->outdata->max_file_size = $this->manager->getOption("max_file_size");
        if ($this->outdata->max_file_size < 0) {
            $this->outdata->max_file_size = 0;
        }
        if (is_null($this->outdata->max_file_size)) {
            $this->outdata->max_file_size_readable = __("Unlimited");
        } else {
            $this->outdata->max_file_size_readable = readableFileSize($this->outdata->max_file_size);
        }

        $this->outdata->total_storage = $this->manager->getOption("max_storage_size");
        $this->outdata->total_storage_readable = $this->outdata->total_storage >= 0
                                               ? readableFileSize($this->outdata->total_storage) 
                                               : __("Unlimited");

        $this->outdata->used_storage = $this->getUsedStorageSize();
        $this->outdata->used_storage_readable = readableFileSize($this->outdata->used_storage);

        $this->outdata->remaining_storage = false;
        if (!is_null($this->outdata->total_storage)) {
            $this->outdata->remaining_storage = $this->outdata->total_storage - $this->outdata->used_storage;
            if ($this->outdata->remaining_storage < 0) {
                $this->outdata->remaining_storage = 0;
            }
            $this->outdata->remaining_storage_readable = readableFileSize($this->outdata->remaining_storage);
        }

        return Common::success($this->outdata);
    }

    /**
     * Retrieve files
     * @return void
     */
    public function retrieve() 
    {
        $input = $this->getInputData();
        $check_hasmore = false;

        // Get retrieve files
        $clauses = $this->clauses;
        $raw_clauses = $this->raw_clauses;
        $sql = "SELECT * FROM ".$this->connector->getOption("table_name")." {clauses_sql} ORDER BY id DESC";
            

        if (isset($input->last_retrieved, $input->limit)) {
            $limit = (int)$input->limit;
            $last_retrieved = (int)$input->last_retrieved;

            if ($last_retrieved > 0) {
                $raw_clauses[] = "id < ".$last_retrieved;
            }

            if ($limit > 0) {
                $sql .= " LIMIT ".$limit.";";
            }

            $check_hasmore = true;
        } else if (isset($input->ids)) {
            $ids = explode(",", $input->ids);
            $valid_ids = [];

            foreach ($ids as $id) {
                $id = (int)$id;
                if ($id > 0 && !in_array($id, $valid_ids)) {
                    $valid_ids[] = $id;
                }
            }

            if (!$valid_ids) {
                $valid_ids[] = 0;
            }
            $raw_clauses[] = "id IN (".implode(",", $valid_ids).")";
        }
        $stmt = $this->runsql($sql, $clauses, $raw_clauses);

        $this->outdata->files = [];
        $this->outdata->last_retrieved = null;
        while ($r = $stmt->fetch(\PDO::FETCH_OBJ)) {
            if ($r->filename) {
                $filepath = $this->manager->getOption("path") . $r->filename;

                if (file_exists($filepath)) {
                    $ext = strtolower(pathinfo($r->filename, PATHINFO_EXTENSION));

                    $denied_exts = $this->manager->getOption("deny");
                    $allowed_exts = $this->manager->getOption("allow");

                    $allowed = true;
                    if (is_array($denied_exts) && in_array($ext, $denied_exts)) {
                        $allowed = false;
                    } else if ($allowed_exts && !in_array($ext, $allowed_exts)) {
                        $allowed = false;
                    }

                    if ($allowed) {
                        $this->outdata->files[] = [
                            "id" => $r->id,
                            "title" => $r->title,
                            "info" => $r->title,
                            "filename" => $r->filename,
                            "filesize" => $r->filesize,
                            "ext" => $ext,
                            "url" => $this->manager->getOption("url") . $r->filename,
                            "date" => $r->date,
                            "icon" => Common::isVideo($r->filename) ? "mdi mdi-play" : false
                        ];

                    }
                }
            }

            $this->outdata->last_retrieved = $r->id;
        }


        $this->outdata->hasmore = false;
        if ($check_hasmore && count($this->outdata->files) > 0) {
            $sql = "SELECT id FROM ".$this->connector->getOption("table_name")." {clauses_sql} ORDER BY id DESC";
            $raw_clauses[] = "id < ".$this->outdata->last_retrieved;
            $sql .= " LIMIT 1;";
            $stmt = $this->runsql($sql, $clauses, $raw_clauses);
            if ($stmt->rowCount() > 0) {
                $this->outdata->hasmore = true;
            }
        }

        return Common::success($this->outdata);
    }


    /**
     * Remove file
     * @return void
     */
    public function remove() 
    {
        $input = $this->getInputData();

        if (!isset($input->id)) {
            return Common::error("File ID is required");
        }

        
        // Get file data
        $sql = "SELECT * FROM ".$this->connector->getOption("table_name")." {clauses_sql} LIMIT 1";
        $clauses = $this->clauses;
        $clauses["id"] = $input->id;
        $stmt = $this->runsql($sql, $clauses);

        if ($stmt->rowCount() == 1) {
            $file = $stmt->fetch(\PDO::FETCH_OBJ);

            // Get remove file
            // Author has been set during the configuration,
            // so this is secure.
            $sql = "DELETE FROM ".$this->connector->getOption("table_name")." {clauses_sql}";
            $clauses = $this->clauses;
            $clauses["id"] = $file->id;
            $stmt = $this->runsql($sql, $clauses);

            // Remove actual file
            @unlink($this->manager->getOption("path") . $file->filename);
        }

        $this->outdata->max_storage_size = $this->manager->getOption("max_storage_size");
        $this->outdata->used_storage_size = $this->getUsedStorageSize();

        return Common::success($this->outdata);
    }


    /**
     * Bulk remove files with an array of the file IDs
     * @return void 
     */
    public function bulkRemove()
    {
        $input = $this->getInputData();

        if (!isset($input->ids)) {
            return Common::error("File ids are required");   
        }

        if (!is_array($input->ids)) {
            return Common::error("Invalid ids");   
        }

        $valid_ids = [];
        foreach ($input->ids as $id) {
            $id = (int)$id;
            if ($id < 1) {
                continue;
            }

            $valid_ids[] = $id;
        }
        $valid_ids = array_unique($valid_ids);

        if ($valid_ids) {
            // Get files data
            $sql = "SELECT * FROM ".$this->connector->getOption("table_name")." {clauses_sql}";
            $raw_clauses = $this->raw_clauses;
            $raw_clauses[] = "id IN (".implode(",", $valid_ids).")";
            $stmt = $this->runsql($sql, null, $raw_clauses);

            $file_addresses = [];
            while ($file = $stmt->fetchObject()) {
                $file_addresses[] = $this->manager->getOption("path") . $file->filename;
            }

            // Get remove files
            // Author has been set during the configuration,
            // so this is secure.
            $sql = "DELETE FROM ".$this->connector->getOption("table_name")." {clauses_sql}";
            $raw_clauses = $this->raw_clauses;
            $raw_clauses[] = "id IN (".implode(",", $valid_ids).")";
            $stmt = $this->runsql($sql, null, $raw_clauses);

            // Remove actual files
            foreach ($file_addresses as $addr) {
                @unlink($addr);
            }
        }


        $this->outdata->max_storage_size = $this->manager->getOption("max_storage_size");
        $this->outdata->used_storage_size = $this->getUsedStorageSize();
        
        return Common::success($this->outdata);
    }


    /**
     * Remove all files from the storage
     * @return void 
     */
    public function clearStorage()
    {
        $input = $this->getInputData();

        // Clear database
        // Author has been set during the configuration, so this is secure.
        $sql = "DELETE FROM ".$this->connector->getOption("table_name")." {clauses_sql}";
        $stmt = $this->runsql($sql, $this->clauses, null);

        // Remove actual files
        // Remove the folder with content and re-create an empty directory
        delete($this->manager->getOption("path"));
        if (!file_exists($this->manager->getOption("path"))) {
            mkdir($this->manager->getOption("path"));
        }

        $this->outdata->max_storage_size = $this->manager->getOption("max_storage_size");
        $this->outdata->used_storage_size = $this->getUsedStorageSize();
        
        return Common::success($this->outdata);
    }


    /**
     * Upload file
     * @return void
     */
    public function upload() 
    {
        $input = $this->getInputData();

        if (!isset($input->type) || !in_array($input->type, array("url", "file"))) {
            return Common::error("Missing/Invalid type");
        }

        if ($input->type == "url") {
            $res = $this->grabFromURL($input->file);
        } else if ($input->type == "file") {
            $this->uploadFile();
        }
    }


    /**
     * Upload file from $_FILE
     * @return \stdClass Result data
     */
    private function uploadFile()
    {
        if (empty($_FILES["file"])) {
            return Common::error("Missing/Empty file");
        }


        try {
            $this->validateFileSize($_FILES["file"]["size"]);
            $ext = strtolower(pathinfo($_FILES["file"]["name"], PATHINFO_EXTENSION));
            $ext_name = pathinfo($_FILES["file"]["name"], PATHINFO_FILENAME);
            $this->validateFileExt($ext);
        } catch (\Exception $e) {
            return Common::error($e->getMessage());
        }


        // Move uploaded file
        $namefile = $ext_name."-".random_string(5);
        $filename = str_replace(" ", "-", $namefile). "." .$ext;
        if (!move_uploaded_file($_FILES["file"]["tmp_name"], $this->manager->getOption("path") . $filename)) 
        {
            return Common::error(__("Couldn't save uploaded file."));
        }

        // Process the media
        $filename = $this->processMedia($filename);

        // File type might be changed, 
        // Get file extension again
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Add file data to filed data table
        $file = $this->addDBRecord([
            "title" => $ext_name,
            "info" => $ext_name,
            "filename" => $filename,
            "filesize" => $_FILES["file"]["size"]
        ]);

        if (!$file) {
            unlink($this->manager->getOption("path") . $filename);
            return Common::error(__("Couldn't save uploaded file data."));
        }

        $this->outdata->file = [
            "id" => $file->id,
            "title" => $file->title,
            "info" => $file->info,
            "filename" => $file->filename,
            "filesize" => $file->filesize,
            "ext" => $ext,
            "url" => $this->manager->getOption("url") . $file->filename,
            "date" => $file->date,
            "icon" => Common::isVideo($file->filename) ? "mdi mdi-play" : false
        ];
        $this->outdata->max_storage_size = $this->manager->getOption("max_storage_size");
        $this->outdata->used_storage_size = $this->getUsedStorageSize();
      
        return Common::success($this->outdata);
    }


    /**
     * Grab file from $url
     * @param  [string] $url File URL
     * @return \stdClass Result data
     */
    private function grabFromURL($url)
    {
        $grabber = new FileGrabber($this);
        $grabber->setUrl($url);
        return $grabber->grab();
    }


    /**
     * Validates file size of the new uploaded (or grabbed) file
     * @param  string  $filesize 
     * @return bool           
     */
    public function validateFileSize($filesize)
    {
        // Check file size
        if ($filesize < 1) {
            throw new \Exception("PHP config error. Empty file");
        } 

        $max_file_size = $this->manager->getOption("max_file_size");
        if (is_null($max_file_size)) {
            $max_file_size = "-1";
        }
        if ($max_file_size <= 0 && $max_file_size != "-1") {
            throw new \Exception("Invalid configuration. Max allowed file size value is not valid.");
        }

        if ($max_file_size < $filesize && $max_file_size != "-1") {
            throw new \Exception(__("File size exceeds max allowed file size."));
        }

        // Check storage size
        $max_storage_size = $this->manager->getOption("max_storage_size");
        if (is_null($max_storage_size)) {
            $max_storage_size = "-1";
        }
        if ($max_storage_size <= 0 && $max_storage_size != "-1") {
            throw new \Exception("Invalid configuration. Max allowed storage size value is not valid.");
        }
        if ($max_storage_size < $this->getUsedStorageSize() + $filesize &&
            $max_storage_size != "-1") 
        {
            throw new \Exception(__("There is not enough storage to upload this file"));
        }

        return true;
    }

    /**
     * Validates type of the new uploaded (or grabbed) file
     * @param  string $ext Extension of the file
     * @return bool
     */
    public function validateFileExt($ext, $allowed_exts = null, $denied_exts = null)
    {
        if (!$ext) {
            throw new \Exception(__("Couldn't detect file extension!"));
        }

        if (substr($ext, 0, 1) == ".") {
            $ext = substr($ext, 1);
        }


        $denied_exts = is_array($denied_exts) 
                     ? $denied_exts
                     : $this->manager->getOption("deny");
        $allowed_exts = is_array($allowed_exts) 
                      ? $allowed_exts 
                      : $this->manager->getOption("allow");

        $allowed = true;
        if (is_array($denied_exts) && in_array($ext, $denied_exts)) {
            $allowed = false;
        } else if ($allowed_exts && is_array($allowed_exts) && !in_array($ext, $allowed_exts)) {
            $allowed = false;
        }

        if (!$allowed) { 
            throw new \Exception(__("File type is not allowed."));
        }

        return true;
    }


    /**
     * Process the media file
     * Resize, convert, crop, watermark etc..
     * @param  string $filename Basename of the file
     * @return string           Processed media filename (not full path)
     */
    public function processMedia($filename)
    {
        $input = $this->getInputData();

        if (isset($input->keep_original_file) && $input->keep_original_file) {
            return $filename;
        }

        try {
            if (Common::isImage($filename)) {
                $filename = $this->processImage($filename);
            }
        } catch (\Exception $e) {
            return Common::error($e->getMessage());
        }

        return $filename;
    }

    /**
     * Process the images
     * @param  string $filename Base name of the file 
     * @return string           Processed media filename (not full path)
     */
    private function processImage($filename) 
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ext_name = pathinfo($filename, PATHINFO_FILENAME);

        if ($ext == "png"){
            try {
                $image = new \claviska\SimpleImage();
                $new_filename = $ext_name.".png";
                $image->fromFile($this->manager->getOption("path") . $filename)
                      ->toFile($this->manager->getOption("path") . $new_filename, "image/jpeg");
                $ext = "png";
                $filename = $new_filename;
            } catch (\Exception $e) {
                return Common::error($e->getMessage());
            }
        }

        return $filename;
    }

}
