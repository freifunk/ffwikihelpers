<?php

class FFWikiHelpers {

public static function onParserFirstCallInit( Parser $parser ) {
        $parser->setHook("owmknoten", [ self::class, "renderOWMKnoten"] );
        $parser->setHook("hamburgknoten", [ self::class, "renderHamburgKnoten"] );
        $parser->setHook("ffcommunityapiinfobox", [ self::class, "renderFFCommunityAPIInfobox" ] );
        $parser->setHook("preisvergleich", [ self::class, "renderPreisvergleichTag" ] );
}

// *********************** owmknoten/hamburgknoten

public static function owmGetUrl($url) {
	$id = "owmknoten_" . $url;
	global $wgMemc;
	$res = $wgMemc->get($id);
	if($res === false) {
	  $ctx = stream_context_create(["http" => ["method" => "GET"]]);
	  $fp = @fopen($url, "r", false, $ctx);
	  if ($fp === false) return "";
	  $res = stream_get_contents($fp);
	  $wgMemc->set($id, $res, 86400);
	}
	return $res;
}

public static function getOWMNodeNames($latlon) {
	$latlon = explode(",", $latlon);
	if(!is_numeric($latlon[0]) || !is_numeric($latlon[1])) return array();
	$url = "https://api.openwifimap.net/view_nodes_spatial?bbox=" . ($latlon[1] - (0.001)) . "," . ($latlon[0] - (0.0005)) . "," . ($latlon[1] + (0.001)) . "," . ($latlon[0] + (0.0005));
	$json = json_decode(self::owmGetUrl($url));
        if(!$json) return [];
        $res = array();
	foreach($json->rows as $row) {
	  $datea = date_parse($row->value->mtime);
	  $mtime = mktime($datea['hour'], $datea['minute'], $datea['second'], $datea['month'], $datea['day'], $datea['year']);
	  if((time() - $mtime) > (7*24*60*60)) continue;
	  array_push($res, $row->id);
	}
	natcasesort($res);
	return $res;
}

public static function renderOWMKnoten($input, array $args, Parser $parser, PPFrame $frame) {
	try {
	  $input = $parser->recursiveTagParse($input, $frame);
	  $latlon = trim($input);
	  $wikiCode = "";
	  $nodes = self::getOWMNodeNames($latlon);
	  foreach($nodes as $node) {
	    $shortNode = str_replace(".olsr", "", $node);
	    if($wikiCode != "") $wikiCode .= ", ";
	    $monitor = "https://monitor.berlin.freifunk.net/host.php?h=".$shortNode;
	    if(strpos(self::owmGetUrl($monitor), "Unknown host") !== false) {
	      $monitor = "";
	    } else {
	      $monitor = ", [$monitor Monitor]";
	    }
	    $wikiCode .= "$shortNode (".
                         "[https://openwifimap.net/#detail?node=".$node." OWM], ".
                         "[https://hopglass.berlin.freifunk.net/#!v:m;n:".$node." Hopglass]".
                         "$monitor)[[Hat Knoten::$shortNode|]]";
	  }
	  if($wikiCode == "") $wikiCode = "keine Knoten online";
	  $output = $parser->recursiveTagParse($wikiCode, $frame);
	} catch(Exception $e) {
	  return "Fehler owmknoten-Tag";
	}
        return $output;
}

public static function renderHamburgKnoten($input, array $args, Parser $parser, PPFrame $frame) {
	try {
	  $input = $parser->recursiveTagParse($input, $frame);
	  $nodes = explode(",", trim($input));
	  $url = "https://hopglass-backend.hamburg.freifunk.net/nodelist.json";
	  $json = json_decode(self::owmGetUrl($url));
	  if(!is_object($json)) throw new Exception("Hamburg JSON invalid");
	  $wikiCode = "";
	  foreach($nodes as $node) {
	    if($wikiCode != "") $wikiCode .= ", ";
	    foreach($json->nodes as $row) {
	      if(strcasecmp($row->name ?? "", $node) == 0) {
	        $wikiCode .= "[https://map.hamburg.freifunk.net/#!n:".$row->id." $node]";
	        break;
	      }
	    }
	  }
	  if($wikiCode == "") $wikiCode = "Keine Knoten online";
	  $output = $parser->recursiveTagParse($wikiCode, $frame);
	} catch(Exception $e) {
	  return "Fehler hamburgknoten-Tag";
	}
        return $output;
}


// *********************** ffcommunityapiinfobox

public static function ffcaGetUrl($url) {
	$id = "ffcaib_" . $url;
	global $wgMemc;
	$res = $wgMemc->get($id);
	if($res === false) {
	  $ctx = stream_context_create(["http" => ["method" => "GET"]]);
	  $fp = @fopen($url, "r", false, $ctx);
	  if(!$fp) throw new Exception("Kann ffcommunityapiinfobox-JSON nicht laden");
	  $res = stream_get_contents($fp);
	  $wgMemc->set($id, $res, 86400);
	}
	return $res;
}

public static function renderFFCommunityAPIInfobox($input, array $args, Parser $parser, PPFrame $frame) {
	try {
	  $input = trim($input);
	  $json = json_decode(self::ffcaGetUrl($input));
	  if(!$json) return "Fehler ffcommunityapiinfobox-Tag (json fehlerhaft)";
	  if(isset($json->location->geoCode->lat) && isset($json->location->geoCode->lon)) {
	    $coor = $json->location->geoCode->lat . "," . $json->location->geoCode->lon;
	  } else if(isset($json->location->lat) && isset($json->location->lon)) {
	    $coor = $json->location->lat . "," . $json->location->lon;
	  } else return "Fehler ffcommunityapiinfobox-Tag (location.geoCode.lat/lon fehlt)";
	  $wikiCode = "{{Infobox Freifunk-Community\n";
	  $wikiCode .= "| Name = " . $json->name . "\n";
	  $wikiCode .= "| Koordinaten = " . $coor . "\n";
	  if(isset($json->location->address->Name)) {
	    $wikiCode .= "| Adresse = " . @$json->location->address->Name .
	       ", " . @$json->location->address->Street .
	       ", " . @$json->location->address->Zipcode .
	       " " . @$json->location->City . "\n";
	  } else if(isset($json->location->address->name)) {
	    $wikiCode .= "| Adresse = " . @$json->location->address->name .
	       ", " . @$json->location->address->street .
	       ", " . @$json->location->address->zipcode .
	       " " . @$json->location->City . "\n";
	  }
	  if(isset($json->url)) $wikiCode .= "| Homepage = " . $json->url . "\n";
	  $wikiCode .= "}}\n";
	  $wikiCode .= "[[Hat FFCommunityAPIFile::$input|]]\n";
          $output = $parser->recursiveTagParse($wikiCode, $frame);
	} catch(Exception $e) {
	  return "Fehler ffcommunityapiinfobox-Tag";
	}
        return $output;
}


// *********************** preisvergleich

public static function preisvergleichGetUrl($url) {
	global $wgMemc;
	$filename = "/dev/shm/preisvergleich_" . str_replace(".", "%2E", urlencode($url));
	$res = "?";
	$outdated = true;
	if(file_exists($filename)) {
	  $res = file_get_contents($filename);
	  $outdated = (time()-filemtime($filename) > rand(24*2*3600, 24*5*3600));
	}
	if(($res === "?") || $outdated) {
	  if($wgMemc->get("preisvergleich_blocked") === "true") {
	    return $res;
	  }
	  $requestsLastMin = $wgMemc->incrWithInit("preisvergleich_throttle", 60, 1, 1);
	  if($requestsLastMin > 5) {
	    // throttle to max 5 requests per minute
	    return $res;
	  }
	  if($requestsLastMin > 1) {
	    // not great but easy way to throttle a bit
	    sleep(2);
	  }
	  $ctx = stream_context_create(["http" => ["method" => "GET"]]);
          set_error_handler(function() {}); // avoid 404 warnings, etc.
	  $fp = fopen($url, "r", false, $ctx);
          restore_error_handler();
	  if ($fp === false) return $res;
	  $newres = stream_get_contents($fp);
	  if(strlen($newres) < 500) {
	    // in case we're blocked by GH, no further requests for 10 minutes
	    $wgMemc->set("preisvergleich_blocked", "true", 600);
	    return $res;
	  }
	  $res = $newres;
	  file_put_contents($filename . "_new", $res);
	  rename($filename . "_new", $filename);
	}
	return $res;
}

public static function preisvergleichGetPrice($html, $url) {
  $dom = new DOMDocument();
  $result = "-";
  if($html !== "?") {
    @$dom->loadHTML($html);
  } else {
    $result = "ca. ?€";
  }
  $xpath = new DOMXPath($dom);
  $prices = $xpath->query("//span[@class='gh_price']");
  foreach ($prices as $price) {
    try {
      $value = $price->firstChild->nodeValue;
      if(!(substr($value, 0, 4)==="€ ")) {
        continue;
      }
    } catch(Exception $e) {
      continue;
    }
    $value = substr($value, 4);
    $value = str_replace("--", "00", $value);
    $value = str_replace(",", ".", $value);
    $value = floatval($value);
    $value += 0.99;
    $result = "ca. " . intval($value) . "€";
    break;
  }
  return "<a class=\"external text\" href=\"" . $url . "\">" . $result . "</a>";
}

public static function renderPreisvergleichTag($input, array $args, Parser $parser, PPFrame $frame) {
	$urlprefix = "https://www.heise.de/preisvergleich/";
	try {
	  $input = trim($input);
	  if(!(substr($input, 0, strlen($urlprefix))===$urlprefix)) {
	    return "Preisvergleich: Nicht unterstuetzte URL";
	  }
	  if(strlen($input)>200) {
	    return "Preisvergleich: URL zu lang";
	  }
	  $input = $urlprefix . preg_replace("/[^a-zA-Z0-9\-\.]/", "_", substr($input, strlen($urlprefix)));
	  $html = self::preisvergleichGetUrl($input);
	  return self::preisvergleichGetPrice($html, $input);
	} catch(Exception $e) {
	  return "Preisvergleich: Fehler";
	}
}

} // end class FFWikiHelpers


if (!defined('MEDIAWIKI')) {
        print("this is broken");
	$nodes = FFWikiHelpers::getOWMNodeNames("52.5444,13.35256");
	foreach($nodes as $node) {
	  echo $node . "\n";
	}
	$datea = date_parse("2015-03-24T13:08:04.596Z");
	$time = mktime($datea['hour'], $datea['minute'], $datea['second'], $datea['month'], $datea['day'], $datea['year']);
	print_r(time() - $time);
	exit(1);
}


?>
