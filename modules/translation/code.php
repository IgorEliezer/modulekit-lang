<?
global $translation_path;

function translation_init() {
  global $root_path;
  global $translation_path;
  $translation_path="$root_path/data/translation";

  if(!file_exists("$translation_path")) {
    if(!mkdir($translation_path)) {
      print "Error creating translation path '$translation_path'\n";
    }
    chdir($translation_path);
    $p=popen("git clone $root_path $translation_path", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);
  }
}

function translation_print_value($v) {
  global $lang_genders;
  $repl=array("\""=>"\\\"", "\\"=>"\\\\");

  if(is_array($v)&&(sizeof($v)==1))
    $v=$v[0];
  if(is_string($v))
    return "\"".strtr($v, $repl)."\"";

  $ret="array(";
  if($lang_genders[$v[0]]) {
    $ret="array({$lang_genders[$v[0]]}, ";
    array_shift($v);
  }
  elseif(in_array($v[0], array("M", "F", "N"))) {
    $ret="array($v[0], ";
    array_shift($v);
  }

  foreach($v as $vi=>$vv) {
    $v[$vi]=strtr($vv, $repl);
  }
  $ret.="\"".implode("\", \"", $v)."\")";

  return $ret;
}

function translation_files_list() {
  global $translation_path;

  translation_init();

  $ret=array();

  // $lang_str-Files and doc-Files
  $ret[]="php:www/lang/";
  $ret[]="php:www/lang/lang_";
  $ret[]="tags:www/lang/tags_";
  $d=opendir("$translation_path/www/plugins");
  while($f=readdir($d)) {
    $d1=opendir("$translation_path/www/plugins/$f");
    while($f1=readdir($d1)) {
      if(preg_match("/^(.*_)en.doc$/", $f1, $m))
	$ret[]="doc:www/plugins/$f/$m[1]";
    }
    closedir($d1);

    if(file_exists("$translation_path/www/plugins/$f/lang_en.php"))
      $ret[]="php:www/plugins/$f/lang_";
  }
  closedir($d);

  // Categories
  $res=sql_query("select * from category_current", $db_central);
  while($elem=pg_fetch_assoc($res)) {
    $ret[]="category:{$elem['category_id']}:{$elem['version']}";
  }

  return $ret;
}

function ajax_translation_files_list() {
  return translation_files_list();
}

class translation {
  public $lang;

  // constructor
  function __construct($lang) {
    $this->lang=$lang;
  }

  // update_language_list
  function update_language_list() {
    global $translation_path;

    include "$translation_path/www/lang/list.php";
    include "$translation_path/www/lang/{$this->lang}.php";
    $language_list[$this->lang]=$lang_str['lang:current'];

    $f=fopen("$translation_path/www/lang/list.php", "w");
    fwrite($f, "<?\n");
    fwrite($f, "// The UI has been translated to following languages\n");
    $ui_langs[]=$this->lang;
    $ui_langs=array_unique($ui_langs);
    
    fwrite($f, "\$ui_langs=array(\"".implode("\", \"", $ui_langs)."\");\n");
    fwrite($f, "\n");
    fwrite($f, "// A list of all languages we know about\n");
    fwrite($f, "\$language_list=array(\n");
    foreach($language_list as $li=>$ln) {
      fwrite($f, "  \"$li\"=>\"$ln\",\n");
    }
    fwrite($f, ");\n");

    fclose($f);

    chdir($translation_path);
    $p=popen("git add $translation_path/www/lang/list.php", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);
  }

  // save
  function save($changed, $param) {
    global $translation_path;
    global $current_user;
    translation_init();

    // no user ... return
    if($current_user->username=="")
      return "error:not_logged_in";

    if(!$changed)
      return;

    foreach($changed as $f=>$data) {
      if(preg_match("/^(php|tags|doc|category):(.*)$/", $f, $m)) {
	$mode=$m[1];
	$file=$m[2];
      }

      switch($mode) {
	case "php":
	  $this->write_file_php($file, $data);
	  break;
	case "tags":
	  $this->write_file_tags($file, $data);
	  break;
	case "doc":
	  $this->write_file_doc($file, $data);
	  break;
	case "category":
	  $this->write_file_category($file, $data, $param);
	  break;
      }
    }

    $this->update_language_list();

    chdir($translation_path);
    $author=$current_user->get_author();
    $msg=strtr($param['msg'], array("\""=>"\\\""));
    $p=popen("git commit --message=\"$msg\" --author=\"$author\"", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);

    return true;
  }

  // read_file_php
  function read_file_php($base_file) {
    $ret=array();
    global $translation_path;
    $file_en="$translation_path/{$base_file}en.php";
    $file="$translation_path/{$base_file}{$this->lang}.php";
    $help_line=0;

    include "$file";

    $f=fopen($file_en, "r");
    while($r=fgets($f)) {
      if(preg_match("/^ *(#?)\\\$lang_str\[[\"']([^\"']*)[\"']\]\s*=(.*);(.*)/", $r, $m)) {
	$found=false;
	if(isset($lang_str[$m[2]]))
	  $ret[$m[2]]=array('value'=>$lang_str[$m[2]]);
	else
	  $ret[$m[2]]=array();
	if($m[4]&&(preg_match("/^\s*\/\/\s*(.*)/", $m[4], $m1))) {
	  $ret[$m[2]]['help']=$m1[1];
	}
      }
      elseif(preg_match("/^\/\/(.*)$/", $r, $m)) {
	$ret["%$help_line"]=$m[1];
	$help_line++;
      }
    }
    fclose($f);

    // special code for www/lang/lang_*.php
    if($base_file=="www/lang/lang_") {
      global $language_list;
      foreach($language_list as $lang=>$d) {
	if((!isset($ret["lang:$lang"]))&&($lang!=""))
	  $ret["lang:$lang"]=array();
      }
    }

    return array(
      'list'=>$ret,
      'order'=>array_keys($ret),
    );
  }

  // write_file_php
  function write_file_php($f, $data) {
    $lang_str=array();

    global $translation_path;
    $file="$translation_path/$f{$this->lang}.php";
    include $file;
    if(!is_array($data))
      return;
    $lang_str=array_merge($lang_str, $data);

    // Read whole file at once to avoid overwriting
    $f_en=file_get_contents("$translation_path/{$f}en.php", "r");
    $f_en=explode("\n", $f_en);

    // Remove trailing new lines
    while($f_en[sizeof($f_en)-1]=="")
      $f_en=array_slice($f_en, 0, sizeof($f_en)-1);

    $f_t=fopen($file, "w");
    foreach($f_en as $r) {
      if(preg_match("/^ *(#?)\\\$lang_str\[[\"']([^\"']*)[\"']\]\s*=(.*);(.*)/", $r, $m)) {
	if(!$lang_str[$m[2]]) {
	  fputs($f_t, "#\$lang_str[\"$m[2]\"]=$m[3];$m[4]\n");
	}
	else {
	  $value=translation_print_value($lang_str[$m[2]]);
	  $str="\$lang_str[\"$m[2]\"]=$value;$m[4]\n";
	  fputs($f_t, $str);
	}
      }
      else {
	fputs($f_t, "$r\n");
      }
    }
    fclose($f_t);

    chdir($translation_path);
    $p=popen("git add $file", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);
  }

  // write_file_tags
  function write_file_tags($f, $data) {
    global $translation_path;

    // no changes? return ...
    if(!is_array($data))
      return;

    // read default english tags
    $lang_str=array();
    $file="$translation_path/{$f}en.php";
    include $file;
    $lang_def=$lang_str;

    // prepare $lang_str with empty strings from english
    $lang_str=array();
    foreach($lang_def as $k=>$v) {
      $lang_str[$k]="";
    }

    // read old tags file in current language
    $file="$translation_path/$f{$this->lang}.php";
    include $file;

    // add new strings to $lang_str
    $lang_str=array_merge($lang_str, $data);

    // Read whole file at once to avoid overwriting
    $f_en=file_get_contents("$translation_path/{$f}en.php", "r");
    $f_en=explode("\n", $f_en);

    $f_t=fopen($file, "w");

    // write until we reach first empty line (copy only comments)
    $i=0;
    while($f_en[$i]!="") {
      fputs($f_t, "{$f_en[$i]}\n");
      $i++;
    }

    // read all tag keys
    $tag_keys=array();
    foreach($lang_str as $k=>$v) {
      if(preg_match("/^tag:([^=]+)(=.*)?$/", $k, $m)) {
	if(!isset($tag_keys[$m[1]]))
	  $tag_keys[$m[1]]=array();

	$tag_keys[$m[1]][$k]=$v;
      }
    }

    // go through all tag_keys and print all strings
    foreach($tag_keys as $key=>$d) {
      fputs($f_t, "\n// $key\n");
      foreach($d as $k=>$v) {
	if(!$v) {
	  $value=translation_print_value($lang_def[$k]);
	  fputs($f_t, "#\$lang_str[\"$k\"]=$value;\n");
	}
	else {
	  $value=translation_print_value($v);
	  fputs($f_t, "\$lang_str[\"$k\"]=$value;\n");
	}
      }
    }

    fclose($f_t);

    chdir($translation_path);
    $p=popen("git add $file", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);
  }

  // write_file_doc
  function write_file_doc($f, $data) {
    $lang_str=array();

    global $translation_path;
    $file="$translation_path/$f{$this->lang}.doc";

    file_put_contents($file, $data);

    chdir($translation_path);
    $p=popen("git add $file", "r");
    while($f=fgets($p)) /* do nothing */ ;
    pclose($p);
  }

  // read_file_doc
  function read_file_doc($f) {
    $ret=array();
    global $translation_path;
    $file="$translation_path/$f{$this->lang}.doc";

    if(!file_exists($file))
      return array(
	'contents'=>"",
      );

    return array(
      'contents'=>file_get_contents($file),
    );
  }

  // read_file_category
  function read_file_category($f) {
    $ret=array();
    if(!preg_match("/^(.*):(.*)$/", $f, $m))
      return null;

    $category=$m[1];
    $version=$m[2];

    $res=sql_query("select * from category where version='$version'", $db_central);
    $elem=pg_fetch_assoc($res);
    $tags=parse_hstore($elem['tags']);
    $orig_lang=$tags['lang'];
    if(!$orig_lang)
      $orig_lang="en";
    $suffix="";
    if($orig_lang!=$this->lang)
      $suffix=":{$this->lang}";

    $ret["$category:name"]=array(
      'value'=>$tags["name{$suffix}"],
      'help'=>"Category name",
    );
    $ret["$category:description"]=array(
      'value'=>$tags["description{$suffix}"],
      'help'=>"Category description",
    );

    $res_rule=sql_query("select * from category_rule where category_id='$category' and version='$version'", $db_central);
    while($elem_rule=pg_fetch_assoc($res_rule)) {
      $tags=parse_hstore($elem_rule['tags']);
      $rule_id=$elem_rule['rule_id'];

      $ret["$category:$rule_id:name"]=array(
	'value'=>$tags["name{$suffix}"],
	'help'=>"Match: {$tags['match']}",
      );
      $ret["$category:$rule_id:description"]=array(
	'value'=>$tags["description{$suffix}"],
      );
    }

    return array(
      'list'=>$ret,
      'help'=>"Please use the form =array([Gender,] \"Singular\", \"Plural\") where appropriate (see top of page for explanation)",
      'orig_lang'=>$orig_lang,
      'order'=>array_keys($ret),
    );
  }

  // write_file_category
  function write_file_category($file, $data, $param) {
    $file=explode(":", $file);
    $cat=new category($file[0]);

    $cat_lang=$cat->tags->get("lang");
    if(!$cat_lang)
      $cat_lang="en";
    $suffix="";
    if($cat_lang!=$this->lang)
      $suffix=":{$this->lang}";

    foreach($data as $path=>$str) {
      $str=implode(";", $str);
      $path=explode(":", $path);

      if(sizeof($path)==2) {
	$cat->tags->set("{$path[1]}{$suffix}", $str);
      }
      elseif(sizeof($path)==3) {
	if($cat->rules[$path[1]])
	  $cat->rules[$path[1]]->tags->set("{$path[2]}{$suffix}", $str);
      }
    }

    $cat->save($param);
  }

  // read_file
  function read_file($f) {
    global $translation_path;

    if(preg_match("/^(php|tags|doc|category):(.*)$/", $f, $m)) {
      $mode=$m[1];
      $file=$m[2];
    }

    switch($mode) {
      case "php":
      case "tags":
	return $this->read_file_php($file);
      case "doc":
	return $this->read_file_doc($file);
      case "category":
	return $this->read_file_category($file);
    }
  }

  // read
  function read($files=null) {
    $ret=array();
    if(!$files)
      $files=translation_files_list();

    foreach($files as $f) {
      $ret[$f]=$this->read_file($f);
    }

    return $ret;
  }
} // class

function ajax_translation_save($param, $xml, $postdata) {
  if(!lang_code_check($param['lang']))
    return false;

  $param=array_merge($param, json_decode($postdata, true));

  $t=new translation($param['lang']);
  return $t->save($param['changed'], $param);
}

function ajax_translation_read($param) {
  if(!lang_code_check($param['lang']))
    return false;

  $t=new translation($param['lang']);
  return $t->read();
}

function ajax_translation_recreate($param) {
  if(!lang_code_check($param['lang']))
    return false;

  $t=new translation($param['lang']);

  $ret=array();
  $data=$t->read();
  foreach($data as $file=>$d) {
    $ret[$file]=array();
  }

  $t->save($ret, array("msg"=>"Updated translation {$param['lang']} to the newest version"));
}

function translation_main_links($links) {
  $links[]=array(5, "<a href='javascript:translation_open()'>".lang("translation:name")."</a>");
}

//register_hook("main_links", "translation_main_links");
