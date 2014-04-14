<?php
        // Change Me 

    $username = "root";
    $password = "password";
    $backup_dir = "/mnt/backup";
    $dump = "/usr/bin/mysqldump";
    $grep = "/bin/grep";
    $gzip = "/bin/gzip";

        // This should not need changing from here

    function sql_dict($sql){
        $x = mysql_query($sql);
        if ($x) return mysql_fetch_assoc($x);
    }
    
    function cleanup($dir){
        $d = dir($dir);
        echo "Path: " . $d->path . "\n";
        while (false !== ($entry = $d->read())) {
            if ($entry=="." or $entry=="..") continue;
            $e = str_replace(".sql.gz","",$entry);
            $x = sql_dict("describe $e");
            if (!$x) {
                print "Removing old backup file [$entry]\n";
                unlink("$dir/$entry");
            }
        }
        $d->close();
    }

    function crc32_file($filename)
    {
          global $gzip;
        $x = exec("$gzip --list --verbose $filename");
        $x = explode(" ",$x);
        return $x[1];
    }

    if (mysql_connect("localhost",$username,$password)) print "Connected.\n";
    else die("Failed to connect to database."); 
    $dbs = mysql_query("show databases");
    if ($dbs) while ($db = mysql_fetch_array($dbs, MYSQL_ASSOC)) {
        $db = $db['Database'];
        if ($db=="information_schema") continue;
        if (mysql_select_db($db)) print "Selected [$db]\n";
        else die("Failed to select db [$db]");
        foreach (array("schema","data") as $pass){
            $sql = mysql_query("show tables");
            $day = date("l");
            if ($pass=="schema") $dir = "/$backup_dir/$db/schema";
            else $dir =  "/$backup_dir/$db/$day";
            if (!file_exists($dir)) system("mkdir -p $dir");
            if (!file_exists($dir)) die("Couldn't Create $dir");
            if ($pass=="data"){
                $latest = "/$backup_dir/$db/latest";
                unlink($latest);
                system("/bin/ln -s \"$dir\" \"$latest\"");
            }            
            cleanup($dir);
            if ($sql) while ($s = mysql_fetch_assoc($sql)) {
                if (!isset($s["Tables_in_{$db}"])) {
                    print "no result";
                    print_r($sql);
                    die();
                }
                $t = $s["Tables_in_{$db}"];
                if (
                 $pass=="schema" ) $data = "--no-data";
                 else $data = "--lock-tables";
                 $tab = $t;
                 $lim = 30;
                 if (strlen($tab)>$lim) $tab = substr($tab,0,$lim-3)."...";
                 while (strlen($tab)<30) $tab .= " ";
                print "BACKUP: $pass : $day : $db : $tab : ";
                if ($pass=="data"){
                    print "Check : ";
                    $check = sql_dict("check table $t");
                    $check = $check['Msg_text'];
                    print "$check : ";
                    if ($check != "OK") {
                        print "Repair";
                        $repair = sql_dict("repair table $t");
                        $repair = $repair['Msg_text'];
                        print " : $repair : ";
                    }
                    if ($day=="Sunday"){
                        // optimize
                        print "Optimize : ";
                        $type = sql_dict("show table status like '$t'");
                        $type = $type['Engine'];
                        if ($type=="MyISAM") sql("optimize table $t");
                        if ($type=="InnoDB") sql("alter table $t engine='InnoDB'");
                    }
                }
                if (isset($argv[1])){
                    print "Skipping dump\n";
                } else {
                    $temp = "/tmp/backup.$t.sql.gz";
                    $out  = "$dir/$t.sql.gz";
                    print "Dump : ";
                    $cmd = "$dump -u$username -p$password $data --quick --add-drop-table $db $t | $grep -v 'Dump completed' | $gzip -n > $temp";
                    system($cmd);
                    print "CRC32 : ";
                    if (!file_exists($out)){
                        print "Saving  : ";
                        $cmd = "/bin/mv $temp $out";
                        system($cmd);
                    } else {
                        $md5  = crc32_file($temp);
                        $nmd5 = crc32_file($out);
                        if ($md5!=$nmd5) {
                            print "Saving  : ";
                            $cmd = "/bin/mv $temp $out";
                            system($cmd);
                        } else {
                            print "Skipped : ";
                            unlink($temp);
                        }
                    }
                    $size = filesize($out);
                    print "[$size]\n";
                }
            }
        }
    }
?> 
