var lang_str={};

function change_language() {
  var ob=document.getElementById("lang_select_form");
//  ob.action=get_permalink();
  ob.submit();
}

function lang_element(str, count) {
  var l;

  if(l=lang_str[str]) {
    if(typeof(l)=="string")
      return l;

    var i;
    if(l.length>1) {
      if((count===0)||(count>1))
        i=1;
      else
        i=0;

      // if a Gender is defined, shift values
      if(typeof(l[0])=="number")
        i++;

      return l[i];
    }
    else if(l.length==1)
      return l[0];
  }

  debug(str, "language string missing");

  if((l=str.match(/^tag:([^=]+)=(.*)$/))&&(l=lang_str["tag:*="+l[2]])) {
    // Boolean values, see:
    // http://wiki.openstreetmap.org/wiki/Proposed_features/boolean_values
    return l;
  }
  else if(l=str.match(/^tag:([^><=!]*)(=|>|<|>=|<=|!=)([^><=!].*)$/)) {
    return l[3];
  }
  else if(l=str.match(/^tag:([^><=!]*)$/)) {
    return l[1];
  }

  if(l=str.match(/^[^:]*:(.*)$/))
    return l[1];

  return str;
}

function lang(str, count) {
  var el=lang_element(str, count);

  if(arguments.length<=2)
    return el;

  var vars=[];
  for(var i=2;i<arguments.length;i++) {
    vars.push(arguments[i]);
  }

  return vsprintf(el, vars);
}

function lang_dom(str, count) {
  var el=lang_element(str, count);
  var ret="";

  if(arguments.length<=2) {
    ret=el;
  }
  else {
    var vars=[];
    for(var i=2;i<arguments.length;i++) {
      vars.push(arguments[i]);
    }

    ret=vsprintf(el, vars);
  }

  var dom=document.createElement("span");
  dom.setAttribute("lang_str", str);
  dom_create_append_text(dom, ret);
  
  return dom;
}

function t(str, count) {
  // TODO: write deprecation message to debug

  return lang(str, count);
}

// replace all {...} by their translations
function lang_parse(str, count) {
  var ret=str;
  var m;

  while(m=ret.match(/^(.*)\{([^\}]+)\}(.*)$/)) {
    var args=arguments;
    args[0]=m[2];

    ret=m[1]+lang.apply(this, args)+m[3];
  }

  return ret;
}

function lang_change(key, value) {
  // When the UI language changed, we have to reload
  if(key=="ui_lang") {
    if(value!=ui_lang) {
      // create new path
      var new_href=get_baseurl()+"#?"+hash_to_string(get_permalink());

      // Set new path and reload
      location.href=new_href;
      location.reload();
    }
  }

  // When the data language changed, we just remember
  // TODO: reload data
  if(key=="data_lang") {
    data_lang=value;
  }
}

function lang_code_check(lang) {
  return lang.match(/^[a-z\-]+$/);
}

function lang_init() {
  if(!options_get("ui_lang"))
    options_set("ui_lang", ui_lang);
  if(!options_get("data_lang"))
    options_set("data_lang", "auto");
}

register_hook("options_change", lang_change);
register_hook("init", lang_init);
