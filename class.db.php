<?php
/*************************************************************
 * author = Aleksej Vasiljkovic
 * AFTER CHANGING THIS FILE, UPDATE THE FOLLOWING INFOMATION:
 * the update-bot will add/skip this script to the updates
 * according to this information.
 *
 * changed by = ava
 * version = 1.00
 * changedate = 05.04.2006
 * status = release
 *************************************************************/

/**
 * DB objekt das connections erstellt, daten ausliest, usw
 *
 * idee:
 * Zentrale kontrolle �ber SQL abfragen und dynamisches switchen von datenbank typen (nicht fertig)
 *
 * @author Aleksej Vasiljkovic <achmed20@gmx.net>
 * @version db 2005-04-06
 * @access public
 */
class DB
{

    /**
     * @access    public
     */
    var $host = "localhost";
    /**
     * @access    public
     */
    var $db = "db";
    /**
     * @access    public
     */
    var $timezone = "Europe/Berlin";
    /**
     * @access    public
     */
    var $user = "user";
    /**
     * @access    public
     */
    var $pass = "password";
    /**
     * @access    public
     */
    var $port = 3306;
    /**
     * @access    public
     */
    var $new_connection = true;
    /**
     * @access    public
     */
    var $debug = false;

    /**
     * @access    public
     */
    var $log = false;

    /**
     * @access    public
     */
    var $cacheTables = false;
    var $cacheMaxSize = 1024000;
    var $cacheTimeOut = 60;
    var $cachePath = "/tmp/dbcache/";
    var $cacheData = false;


    /**
     * directory f�r logfiles
     * @access    public
     */
    var $root_log = "/tmp/";

    /**
     * @access    private
     */
    var $sql = "";

    /**
     * timeused for last query
     * @access    public
     */
    var $time = 0;

    /**
     * timeused for all query
     * @access    public
     */
    var $time_total = 0;

    /**
     * history of all queires done by this object
     * @access    public
     */
    var $query_history = "";

    var $conn = false;

    var $permanent = false;

    var $nocache = false;

    var $initial_command = false;

    var $fallback = false;

    // is the database undergoing service?
    var $service = false;


    /**
     * initialisiert DB object
     * @param    string $host   DB Server
     * @param    string $user   user/login
     * @param    string $passwd passwort
     * @param    string $db     Datenbankname
     * @param int       $port
     * @param bool      $new
     * @param bool|DB      $fallback
     * @access    public
     */
    function DB($host = "localhost", $user = "root", $passwd = "", $db = "db", $port = 3306, $new = false, $fallback = false)
    {
        global $_GET;
        $this->host = $host;
        $this->db = $db;
        $this->user = $user;
        $this->pass = $passwd;
        $this->port = $port;
        $this->type = "mysql";
        $this->fallback = $fallback;


        $this->writeto = false;
        $this->writeboth = false;

        $this->debug = false;

        if ($_GET["logsql"] == "achmed") {
            $this->log = true;
        }

        $this->changelog = false;
        $this->sql = "";
        $this->time = 0;
        $this->time_total = 0;
        $this->query_history = "";
//       register_shutdown_function($this->close());
    }

    function get_connection()
    {
        if (!$this->conn) {
            $this->connect();
        }

        return $this->conn;
    }

    /**
     * Connects to selected database
     * @access    public
     * @param bool $force_temp_connect
     * @return string connection ID
     */
    function connect($force_temp_connect = false)
    {
        // FIXME: add MYSQL_CLIENT_SSL as mysql flag to enable SSL (EBE)
        if ($this->permanent && !$force_temp_connect) {
            $this->conn = mysql_pconnect($this->host, $this->user, $this->pass, $this->new_connection);
        } else {
            $this->conn = mysql_connect($this->host, $this->user, $this->pass, $this->new_connection);
        }

        if (!$this->conn && $this->fallback) {
            // use fallback connection
            $this->conn = $this->fallback->get_connection();
        }

        if ($this->db) {
            mysql_select_db($this->db, $this->conn);
        }
        // set binlog_format to row
        mysql_query("SET BINLOG_FORMAT = ROW;", $this->conn);
        if ($this->timezone) {
            mysql_query("set time_zone = '{$this->timezone}';", $this->conn);
        }
        if ($this->initial_command) {
            $tmp = explode(";", $this->initial_command);
            foreach ($tmp as $init) {
                mysql_query("$init;", $this->conn);
            }
        }

        return $this->conn;
    }

    /**
     * Connects to selected database
     * @access    public
     * @return    string connection ID
     */
    function check_connection()
    {
        if (!$this->conn) {
            $this->connect();
        }
        if ($this->permanent) {
            mysql_select_db($this->db, $this->conn);
        }

        return $this->conn;
    }


    /**
     * Creates object->numrows and returns number of returned rows
     * @access    public
     * @return    integer number of returned Rows
     */
    function getNumRows()
    {
        $this->numrows = 0;
        if (is_object($this->result)) {
            $this->numrows = mysql_num_rows($this->result);
        }

        return $this->numrows;
    }

    /**
     * executes a Query
     * @param      string $query SQL Query
     * @param bool $hide_error
     * @param bool $unbuffered
     * @return string return Result ID
     * @access    public
     */
    function query($query, $hide_error = false, $unbuffered = false)
    {
        global $DBCONFIG;
        $this->error_msg = false;
        $this->cacheData = false;

        if ($this->isCacheable($query)) {
            if ($this->cacheData = $this->getCache($query)) {
                $this->sql = $query;

                return "cached data";
            }
        }

        $this->check_connection();

        if ($DBCONFIG["DB_NO_DELAYED"] && stristr($query, " DELAYED ")) {
            $query = str_ireplace(" delayed ", " ", $query);
        }

        if ($unbuffered) {
            //die($query);
            mysql_unbuffered_query($query, $this->conn);

            return true;
        }
        $time_start = $this->getmicrotime();
        $result = mysql_query($query, $this->conn);

        $this->row = false;
        $this->error = "<b>mysql query:</b>$query <br>\n<b>mysql error:</b>" . mysql_error($this->conn) . "<br>\n";
        $this->error_msg = mysql_error($this->conn);
        if ($this->debug && !$result && !$hide_error) {
            echo $this->error;
        }
        $this->result = &$result;
        $this->sql = &$query;
        $this->time = round($this->getmicrotime() - $time_start, 5);
        $this->time_total += $this->time;


        if ($this->isCacheable($query)) {
            $this->cacheData = $this->getAll();
            if ($this->setCache($this->cacheData, $query)) {
                return "cached data";
            } else {
                $this->cacheData = false;
                @mysql_data_seek($this->result, 0);
            }
        }

        if ($this->log) {
            $this->logfile($query);
        }

        return $result;
    }

    /**
     * cycles through SQL results and creates object->row->[fieldname]
     * @access    public
     * @return    object mysql_fetch_object
     */
    function next()
    {
        if ($this->cacheData) {
            $ret = current($this->cacheData);
            next($this->cacheData);

            return $ret;
        }
        if ($this->result) {
            $this->row = mysql_fetch_object($this->result);
            if ($this->row) {
                return $this->row;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

#############################################next()###############################################
#  cycles through sql results
#  creates object->row[]
##################################################################################################
    /**
     * returns all rows as array(object)
     * @access    public
     * @return    array all rows as array(object)
     */
    function getAll()
    {
        if ($this->cacheData) {
            return $this->cacheData;
        }
        $row = "";
        $tmp = "";
        $i = 0;
        if ($this->result) {
            $this->row = false;
            while ($tmp = mysql_fetch_object($this->result)) {
                $row[$i++] = $tmp;
            }
            $this->row =& $row;
            if ($this->row) {
                return $row;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }


    /**
     * returns all rows as array(Array())
     * @access    public
     * @return    array all rows as array(Array)
     */
    function getAllArray($assoc = false)
    {
        if ($this->cacheData) {
            $tmp = $this->cacheData;
            foreach ($tmp as &$t) {
                $t = (array)$t;
            }

            return $tmp;
        }
        $row = "";
        $tmp = "";
        $i = 0;
        if ($this->result) {
            $this->row = false;
            if ($assoc) {
                while ($tmp = mysql_fetch_assoc($this->result)) {
                    $row[$i++] = $tmp;
                }
            } else {
                while ($tmp = mysql_fetch_array($this->result)) {
                    $row[$i++] = $tmp;
                }
            }


            $this->row =& $row;
            if ($this->row) {
                return $row;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * cycles through SQL results and creates object->row[fieldname]
     * @access    public
     * @return    array array[fieldname]
     */
    function next_array($assoc = false)
    {
        if ($this->cacheData) {
            $ret = (array)current($this->cacheData);
            next($this->cacheData);

            return $ret;
        }
        if ($this->result) {
            if ($assoc) {
                $this->row = &mysql_fetch_assoc($this->result);
            } else {
                $this->row = &mysql_fetch_array($this->result);
            }
            if ($this->row) {
                return $this->row;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    /**
     * returns basic HTML table with some debug info and all found rows
     * @access    public
     * @return    string html table
     */
    function getHTML($nosql = false)
    {
        $i = 0;
        if ($this->cacheData) {
            reset($this->cacheData);
            $fields = count((array)current($this->cacheData));
            $field_names = array_keys((array)current($this->cacheData));
            $html = "<table ID=DATA border=1 cellpadding=0 cellspacing=0 class=text>";
            if (!$nosql) {
                $html .= "<tr><td colspan='" . $fields . "'><b>cached SQL:</b> <i>" . $this->sql . "</i></td></tr>";
            }
            //headline
            $html .= "<tr>";
            foreach ($field_names as $name) {
                $html .= "<th>$name</th>\n";
            }
            $html .= "</tr>\n";

            foreach ($this->cacheData as $row) {
                $html .= "<tr>\n";
                foreach ($row as $data) {
                    $html .= "<td>" . $data . "</td>\n";
                }
            }
            $html .= "</tr></table>";
            reset($this->cacheData);

            return $html;
        }

        if ($this->result) {
            @mysql_data_seek($this->result, 0);
            $fields = mysql_num_fields($this->result);
            $html = "<table ID=DATA border=1 cellpadding=0 cellspacing=0 class=text>";
            $html .= "<tr><td colspan='" . $fields . "'><b><i>" . $this->sql . "</i></b> (" . $this->time . ") </td></tr>";
            $html .= "<tr>";
            for ($i = 0; $i < $fields; $i++) {
                $html .= "<td><b>" . mysql_field_name($this->result, $i) . "</b></td>\n";
            }
            while ($row = mysql_fetch_array($this->result)) {
                $html .= "</tr><tr>\n";
                for ($i = 0; $i < $fields; $i++) {
                    $html .= "<td>" . $row[$i] . "</td>\n";
                }
            }
            $html .= "</tr></table>";
            @mysql_data_seek($this->result, 0);
        }

        return $html;
    }

    /**
     * returns basic CSV table with some debug info and all found rows
     * @access    public
     * @return    string html table
     */
    function getCSV($seperator = ";", $encap = "\"", $nl = "\r\n", $append = false, $decode = false, $noEncapNumbers=false)
    {
        @mysql_data_seek($this->result, 0);
        $i = 0;
        if ($this->result) {
            $fields = mysql_num_fields($this->result);
            $ary_fields = array();
            for ($i = 0; $i < $fields; $i++) {
                $html .= $encap . str_replace('"', '\"', mysql_field_name($this->result, $i)) . $encap . $seperator;
            }


            while ($row = mysql_fetch_array($this->result)) {
                $html .= $nl;
                for ($i = 0; $i < $fields; $i++) {
                    $row[$i] = str_replace(chr(13), '', $row[$i]);
                    $row[$i] = str_replace(chr(10), '', $row[$i]);
                    if ($noEncapNumbers) {
                        if (is_numeric($row[$i])){
                            $html .= $row[$i] . $seperator;    
                        } else if (is_null($row[$i]) || $row[$i]==="") {
                            $html .= $seperator;    
                        } else {
                            $html .= $encap . str_replace('"', '\"', $row[$i]) . $encap . $seperator;
                        }
                    }else {
                        $html .= $encap . str_replace('"', '\"', $row[$i]) . $encap . $seperator;
                    }
                }
                if (function_exists($append)) {
                    $html .= $append($row, $seperator, $encap, $nl);
                }
            }
        }
        $html .= $nl;
        @mysql_data_seek($this->result, 0);
        if ($decode) {
            $html = utf8decode($html);
        }

        return $html;
    }

    /**
     * returns TXT formated rows like \G
     * @access    public
     * @return    string html table
     */
    function getTXT()
    {
        @mysql_data_seek($this->result, 0);
        $rc = 0;
        $fnames = false;
        $txt = "";
        if ($this->result) {
            $fields = mysql_num_fields($this->result);
            $ary_fields = array();
            for ($i = 0; $i < $fields; $i++) {
                $fnames[$i] = mysql_field_name($this->result, $i);
            }


            while ($row = mysql_fetch_array($this->result)) {
                $rc++;
                $txt .= "************************ {$rc}. row ************************\n";
                for ($i = 0; $i < $fields; $i++) {
                    $txt .= str_pad($fnames[$i], 20, " ", STR_PAD_LEFT) . ": " . $row[$i] . "\n";
                }
            }
        }
        @mysql_data_seek($this->result, 0);

        return $txt;
    }

    /**
     * writes basic CSV table
     * @access    public
     * @return    string filename
     */
    function writeCSV($seperator = ";", $encap = "\"", $nl = "\r\n", $append = false, $decode = false)
    {
        @mysql_data_seek($this->result, 0);
        $i = 0;
        if ($this->result) {
            $file = "/tmp/" . md5($this->sql) . "-" . rand(100000, 999999) . ".csv";
            $fh = fopen($file, "w");

            $fields = mysql_num_fields($this->result);
            $ary_fields = array();
            for ($i = 0; $i < $fields; $i++) {
                $html .= $encap . str_replace("'", '\'', str_replace('"', '\"', mysql_field_name($this->result, $i))) . $encap . $seperator;
            }
            fputs($fh, $html);

            while ($row = mysql_fetch_array($this->result)) {
                $html = $nl;
                for ($i = 0; $i < $fields; $i++) {
                    $row[$i] = str_replace(chr(13), '', $row[$i]);
                    $row[$i] = str_replace(chr(10), '', $row[$i]);
                    $html .= $encap . str_replace('"', '\"', $row[$i]) . $encap . $seperator;
                }
                if (function_exists($append)) {
                    $html .= $append($row, $seperator, $encap, $nl);
                }
                if ($decode) {
                    $html = utf8decode($html);
                }
                fputs($fh, $html);
            }

            return $file;
        } else {
            return false;
        }
        $html .= $nl;
        @mysql_data_seek($this->result, 0);
        if ($decode) {
            $html = utf8decode($html);
        }

        return $html;
    }

    /**
     * echos basic CSV table with some debug info and all found rows
     * @access    public
     * @return    string html table
     */
    function echoCSV($seperator = ";", $encap = "\"", $nl = "\n", $append = false, $decode = false)
    {
        $i = 0;
        $first = true;
        if ($this->result) {
            $fields = mysql_num_fields($this->result);
            for ($i = 0; $i < $fields; $i++) {
                echo $encap . str_replace('"', '\"', mysql_field_name($this->result, $i)) . $encap . $seperator;
            }
            while ($row = mysql_fetch_array($this->result)) {
//				if($first && function_exists($append)){
//					echo $append($row, $first, $seperator, $encap, $nl);
//					$first = false;
//				}

                echo $nl;
                for ($i = 0; $i < $fields; $i++) {
                    if ($decode) {
                        $row[$i] = utf8decode($row[$i]);
                    }

                    echo $encap . str_replace('"', '\"', $row[$i]) . $encap . $seperator;
                }
                if (function_exists($append)) {
                    echo $append($row, $first, $seperator, $encap, $nl);
                }
            }
        }
        echo $nl;

        return true;
    }


    /**
     * returns InsertID (after successfull insert)
     * @access    public
     * @return    integer unique ID
     */
    function getInsertID()
    {
        $this->insertid = mysql_insert_id($this->conn);

        return $this->insertid;
    }


    /**
     * returns number of rows affected by sql updates, inserts, delete, ...
     * @access    public
     * @return    integer number of affected rows
     */
    function affected()
    {
        return mysql_affected_rows($this->conn);
    }

    /**
     * closes current connection to DB server
     * @access    public
     * @return    bolean true
     */
    function close()
    {
        mysql_close($this->conn);

        return true;
    }


    /**
     * returns all known vars
     * @access    public
     * @return    string html list
     */
    function getVars()
    {
        $vars = get_object_vars($this);
        echo "<b>object vars</b>";
        foreach ($vars as $name => $value) {
            echo "<li>" . $name . " : " . $value;
        }
    }

    /**
     * if current sql query contains change actions, log them ...
     * @access    private
     * @return    bolean true
     */
    function changeLog($info = "", $logroot = false)
    {
        global $user, $S_LOGIN, $seite;

        if (is_object($user)) {
            $name = $user->name;
        } else {
            $name = $S_LOGIN;
        }

        if (strtoupper(substr(trim($info), 0, 6)) == "DELETE" ||
            strtoupper(substr(trim($info), 0, 6)) == "INSERT" ||
            strtoupper(substr(trim($info), 0, 6)) == "UPDATE"
        ) {

            $logTXT = "";
            $logTXT .= "$name\t$seite\t" . date("Y-m-d H:i:s") . "\t";
            $logTXT .= "$info";


            if (!$logoroot) {
                $logroot = realpath($this->root_log);
            }
            $filename = "$logroot/change_" . date("Y_n_j") . ".txt";
            $logH = fopen($filename, "a+");
            fwrite($logH, $logTXT . "\n");
            fclose($logH);
        }

        return true;
    }

    /**
     * logs all SQL queries
     * @access    private
     * @return    bolean true
     */

    function logfile($info = "", $logroot = false)
    {
        $logTXT = "-----------" . date("h:i:s") . "-----------\n";
        $logTXT .= trim($info) . "\n";
        $logTXT .= mysql_info() . " ([{$this->db}] " . $this->time . " ms) \n";


        if (!$logroot) {
            $logroot = realpath($this->root_log);
        }
        $logH = fopen($logroot . "/query_" . date("Y_n_j") . ".txt", "a+");
        fwrite($logH, $logTXT . "\n");
        fclose($logH);

        return true;
    }

    public static function fix($str, $convert = true)
    {
        if (!is_numeric($str) && $convert) {
            $ary[] = "UTF-8";
            $ary[] = "ASCII";
            $ary[] = "ISO-8859-1";

            $encoding = mb_detect_encoding($str, $ary, true);
            if ($encoding != "UTF-8") {
                $str = mb_convert_encoding($str, "UTF-8");
            }
        }
        $str = addslashes($str);
        //$str = str_replace("'", "\'", $str);
        //$str = mysql_real_escape_string($str);

        return $str;
    }

    function copy_table(& $source_db, $source_table, & $target_db, $target_table, $temporary = false, $filter = false, $show_progress = false, $drop_target = true, $convertEngine = "")
    {
        //ziel tabelle loeschen
        if ($drop_target) {
            $target_db->query("drop table if exists $target_table");
        }

        $source_db->query("show create table $source_table");
        $structure = $source_db->next_array();
        $structure = str_replace($structure[0], $target_table, $structure[1]);


        if ($temporary)    //temporaray table?
        {
            $structure = str_replace(" TABLE ", " temporary TABLE ", $structure);
        }

        if ($convertEngine) {    //convert to new engine?

            $structure = preg_replace("/ ENGINE=([A-Za-z]+) /", " ENGINE={$convertEngine} ", $structure);

        }

        $target_db->query($structure);
        $target_db->query("alter table {$target_table} disable keys ;");


        if ($filter) {
            $sql_add = "where $filter";
        }
        //datenimport
        $source_db->query("select * from " . $source_table . " $sql_add;");
        $row_count = $source_db->getNumRows();
        $loop = true;
        $skipped = 0;
        $ext_insert = 100;
        $progress = false;
        $pos = 0;
        if ($row_count) {
            while ($loop) {
                $insert = "";
                for ($i = 0; $i < $ext_insert; $i++) {
                    if ($row = $source_db->next_array(true)) {
                        $pos++;
                        $fields = "`" . implode("`,`", array_keys($row)) . "`";
                        $tmp = array();
                        foreach ($row as $key => $val) {
                            $tmp[$key] = db::fix($val);
                        }
                        $data = "'" . implode("','", $tmp) . "'";
                        if (!$insert) {
                            $insert = "INSERT ignore INTO " . $target_table . " ($fields) VALUES ($data)";
                        } else {
                            $insert .= "\n,($data)";
                        }
                    } else {
                        $loop = false;
                    }
                }
                $tmp = round(($pos / $row_count * 100));
                if ($progress != $tmp) {
                    $progress = $tmp;
                    if ($show_progress) {
                        if (!($progress % 5)) {
                            echo date("d.m.Y H:i:s") . " imported $progress % ($pos / $row_count)\n";
                        }
                    }
                }
                if ($insert) {
                    $target_db->query($insert);
                }
            }
        }
        $target_db->query("alter table {$target_table} enable keys ;");

//		$source_db->close();
//		$target_db->close();

    }

    function exportTable(& $source_db, $source_table, $file, $drop_target = true, $filter = false, $show_progress = true, $join = "", $ext_insert = 50)
    {
        $fp = fopen($file, "a+");
        echo date("d.m.Y H:i:s") . " ### exporting '$source_table' to $file ###\n";
        $source_db->query("show create table $source_table");
        $structure = $source_db->next_array();
        fputs($fp, $structure[1] . ";\n");

        if ($filter) {
            $sql_add = "where $filter";
        }
        //datenimport
        $source_db->query("select $source_table.* from " . $source_table . " $join $sql_add;");
        $row_count = $source_db->getNumRows();
        $loop = true;
        $skipped = 0;
        $progress = false;
        $pos = 0;
        if ($row_count) {
            while ($loop) {
                $insert = "";
                for ($i = 0; $i < $ext_insert; $i++) {
                    if ($row = $source_db->next_array(true)) {
                        $pos++;
                        $fields = "`" . implode("`,`", array_keys($row)) . "`";
                        $tmp = array();
                        foreach ($row as $key => $val) {
                            $tmp[$key] = db::fix($val);
                        }
                        $data = "'" . implode("','", $tmp) . "'";
                        if (!$insert) {
                            $insert = "INSERT ignore INTO " . $source_table . " ($fields) VALUES ($data)";
                        } else {
                            $insert .= ",($data)\n";
                        }
                    } else {
                        $loop = false;
                    }
                }
                $tmp = round(($pos / $row_count * 100));
                if ($progress != $tmp) {
                    $progress = $tmp;
                    if ($show_progress == "full") {
                        if (!($progress % 5)) {
                            echo date("d.m.Y H:i:s") . " exported $progress % ($pos / $row_count)\n";
                        }
                    }
                }
                if ($insert) {
                    //$target_db->query($insert);
                    fputs($fp, $insert . ";\n");
                }
            }
        }

    }


    function result()
    {
        return $this->next();
    }

    function get($table, $fields = false, $where = "", $order = "", $limit = "")
    {
        if ($where) {
            $where = "where " . $where;
        }
        if ($order) {
            $order = "order by $order";
        }
        if ($limit) {
            $limit = "limit $limit";
        }
        if ($fields) {
            if (is_array($fields)) {
                $fields = implode(",", $fields);
            }
        } else {
            $fields = "*";
        }

        $sql = "select $fields from $table $where $order $limit";
        $this->query($sql);

        return $this->getAll();
    }

    // ------------------ cache cpart ----------------------

    function isCacheable($sql)
    {
        if ($this->nocache) {
            return false;
        }
        if (is_array($this->cacheTables) && $sql && $this->getNumRows() < 100) {
            $sql = trim($sql);
            $found = false;
            foreach ($this->cacheTables as $t) {
                //not case sensitive
                if (stristr($sql, "from $t")) {
                    $found = true;
                }
            }
            if (strtoupper(substr(trim($sql), 0, 6)) == "SELECT" && $found) {
                return true;
            }
        }

        return false;
    }

    function getCacheFileName($sql, $type = "")
    {
        if ($type) {
            $type = "-" . $type;
        }
        $sql = trim($sql);
        $add = "";
        //if($this->cacheTimeOut==60)$add = date("i")."_";
        $str = $this->cachePath . $this->db . "_" . $add . md5($sql) . $type . ".dbcache";

        return $str;
    }

    function encode($value)
    {
        return (json_encode($value));
    }

    function decode($value)
    {
        return json_decode(($value));
    }


    function getCache($sql, $type = "")
    {
        $fn = $this->getCacheFileName($sql, $type);

        $ftime = @filemtime($fn);
        if (($ftime + $this->cacheTimeOut) < time()) {
            @unlink($str);

            return false;
        }

        $data = false;
        if (file_exists($fn)) {
            $data = $this->decode(file_get_contents($fn));
        }

        return $data;
    }

    function setCache($data, $sql, $type = "")
    {

        if (strlen(json_encode($data)) > $this->cacheMaxSize) {
            return false;
        }

        $fn = $this->getCacheFileName($sql, $type);

        $subdirs = explode("/", $fn);
        $path = "";
        for ($i = 0; $i < (count($subdirs) - 1); $i++) {
            $path .= $subdirs[$i] . "/";
            if (!is_dir($path)) {
                mkdir($path);
            }
        }

        file_put_contents($fn, $this->encode($data));

        return true;
    }

    function addCacheableTable($table)
    {
        $this->cacheTables[$table] = $table;
    }

    function clearCacheTables()
    {
        $this->cacheTables = false;
    }

    function cleanupCache()
    {
        $cmd = new system_cmd();
        $timeout_minutes = (int)ceil(($this->cacheTimeOut + 60) / 60);

        return $cmd->execute("find " . $this->cachePath . "* -mmin +" . $timeout_minutes . " -delete 2>&1 >/dev/null");

    }

    function setTimezone()
    {
        global $ADMIN;
        if ($ADMIN["sql_timezone"]) {
            $this->query("set time_zone = '{$ADMIN['sql_timezone']}'");
        }
    }

    #################################################################################
    function getmicrotime()
    {
        list($usec, $sec) = explode(" ", microtime());

        return ((float)$usec + (float)$sec);
    }
############################################# end class #########################################


}
