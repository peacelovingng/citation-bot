<?php
while ($page) {
	$startPage = time();
	echo $htmlOutput?("\n<hr>[" . date("H:i:s", $startPage) . "] Processing page '<a href='http://en.wikipedia.org/wiki/$page' style='text-weight:bold;'>$page</a>' &mdash; <a href='http://en.wikipedia.org/?title=". urlencode($page)."&action=edit' style='text-weight:bold;'>edit</a>&mdash;<a href='http://en.wikipedia.org/?title=".urlencode($page)."&action=history' style='text-weight:bold;'>history</a> <script type='text/javascript'>document.title=\"Citation bot: '" . str_replace("+", " ", urlencode($page)) ."'\";</script>"):("\n*** Processing page '$page' : " . date("H:i:s", $startPage));
	
	$bot->fetch(wikiroot . "title=" . urlencode($page) . "&action=raw");
	$startcode = $bot->results;
	if ($citedoi && !$startcode) $startcode = $freshcode;
	if (preg_match("/\{\{nobots\}\}|\{\{bots\s*\|\s*deny\s*=[^}]*(Citation[ _]bot|all)[^}]*\}\}|\{\{bots\s*\|\s*allow=none\}\}/i", $startcode, $denyMsg)) {
		echo "**** Bot forbidden by bots / nobots tag: $denyMsg[0]";
		$page = nextPage();
	} else {
		$pagecode = preg_replace("~(\{\{cit(e[ _]book|ation)[^\}]*)\}\}\s*\{\{\s*isbn[\s\|]+[^\}]*([\d\-]{10,})[\s\|\}]+[^\}]?\}\}?~i", "$1|isbn=$3}}", 				
				preg_replace("~(\{\{cit(e[ _]journal|ation)[^\}]*)\}\}\s*\{\{\s*doi[\s\|]+[^\}]*(10\.\d{4}/[^\|\s\}]+)[\s\|\}]+[^\}]?\}\}?~i", "$1|doi=$3}}",
				preg_replace
										("~(?<!\?&)\bid(\s*=\s*)(DOI\s*(\d*)|\{\{DOI\s*\|\s*(\S*)\s*\}\})([\s\|\}])~Ui","doi$1$4$3$5", 
				preg_replace("~(id\s*=\s*)\[{2}?(PMID[:\]\s]*(\d*)|\{\{PMID[:\]\s]*\|\s*(\d*)\s*\}\})~","pm$1$4$3",
				preg_replace("~[^\?&]\bid(\s*=\s*)DOI[\s:]*(\d[^\s\}\|]*)~i","doi$1$2",				
				preg_replace("~url(\s*)=(\s*)http://dx.doi.org/~", "doi$1=$2", $startcode))))));
				
				
	//Search for any duplicate refs with names
	if (false && preg_match_all("~<[\n ]*ref[^>]*name=(\"[^\"><]+\"|'[^']+|[^ ><]+)[^/>]*>(([\s\S](?!<)|[\s\S]<(?!ref))*?)</ref[\s\n]*>~", $pagecode, $refs)) {
		dbg($refs);#############
		$countRefs = count($refs[0]);
		for ($i = 0; $i < $countRefs; $i++) {
			$refs[2][$i] = trim($refs[2][$i]);
			for ($j=0; $j<$i; $j++){
				$refs[2][$j] = trim($refs[2][$j]);
				if (
					strlen($refs[2][$j]) / strlen($refs[2][$i]) > 0.9
					&& strlen($refs[2][$j]) / strlen($refs[2][$i]) <1.1
					&& similar_text($refs[2][$i], $refs[2][$j]) / strlen($refs[2][$i]) >= 1  # We can lower this if we can avoid hitting "Volume II/III" and "page 30/45"
					&& ( similar_text($refs[2][$i], $refs[2][$j]) / strlen($refs[2][$i]) == 1
						|| similar_text($refs[2][$i], $refs[2][$j]) > 52) //Avoid comparing strings that are too short; e.g. "ibid p20" 
					) {if ($_GET["DEBUG"]) dbg(array(
					" i & j " => "$i & $j",
					"J" => $refs[2][$j],
					"Jlen" => strlen($refs[2][$j]),
					"I" => $refs[2][$i],
					"Ilen" => strlen($refs[2][$i]),
					"SimTxt" => similar_text($refs[2][$j],$refs[2][$i]) . " = " . similar_text($refs[2][$i], $refs[2][$j]) / strlen($refs[2][$i])
					));
						$duplicateRefs[$refs[0][$i]] = $refs[1][$j]; // Full text to be replaced, and name to replace it by
					}
			}
		}
		foreach ($duplicateRefs as $text => $name){
			$pagecode = preg_replace("~^([\s\S]*)" . preg_quote("<ref name=$name/>") . "~", "$1" . $text,
									preg_replace("~" . preg_quote($text) . "~", "<ref name=$name/>", $pagecode));
		}
	}
				
###################################  START ASSESSING BOOKS ######################################

		if ($citation = preg_split("~{{((\s*[Cc]ite[_ ]?[bB]ook(?=\s*\|))([^{}]|{{.*}})*)([\n\s]*)}}~U", $pagecode, -1, PREG_SPLIT_DELIM_CAPTURE)) {
			$pagecode = null;
			$iLimit = (count($citation)-1); 
			for ($i=0; $i<$iLimit; $i+=5){//Number of brackets in cite book regexp +1
			$starttime = time();
				$c = preg_replace("~\bid(\s*=\s*)(isbn\s*)?(\d[\-\d ]{9,})~i","isbn$1$3",
					preg_replace("~(isbn\s*=\s*)isbn\s?=?\s?(\d\d)~i","$1$2",
					preg_replace("~(?<![\?&]id=)isbn\s?:(\s?)(\d\d)~i","isbn$1=$1$2", $citation[$i+1]))); // Replaces isbn: with isbn =

				while (preg_match("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", $c)) $c = preg_replace("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", "$1" . pipePlaceholder, $c);
				preg_match(siciRegExp, urldecode($c), $sici);
				 
				// Split citation into parameters
				$parts = preg_split("~([\n\s]*\|[\n\s]*)([\w\d-_]*)(\s*= *)~", $c, -1, PREG_SPLIT_DELIM_CAPTURE);
				$partsLimit = count($parts);
				if (strpos($parts[0], "|") > 0 && strpos($parts[0],"[[") === FALSE && strpos($parts[0], "{{") === FALSE) {	
					set("unused_data", substr($parts[0], strpos($parts[0], "|")+1));
				}
				
				for ($partsI=1; $partsI<=$partsLimit; $partsI+=4) {
					$value = $parts[$partsI+3];
					$pipePos = strpos($value, "|");
					if ($pipePos > 0 && strpos($value, "[[") === false & strpos($value, "{{") === FALSE) {
						// There are two "parameters" on one line.  One must be missing an equals.
						$p["unused_data"][0] .= " " . substr($value, $pipePos);
						$value = substr($value, 0, $pipePos);
					}
					// Load each line into $p[param][0123]
					$p[strtolower($parts[$partsI+1])] = Array($value, $parts[$partsI], $parts[$partsI+2]); // Param = value, pipe, equals
				}
				
				//Make a note of how things started so we can give an intelligent edit summary
				foreach($p as $param=>$value)	if (is($param)) $pStart[$param] = $value[0];	
				 
				useUnusedData();
				
				if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
				else {
					if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
				}
				echo "\n* {$p["title"][0]}";
				
				// Fix typos in parameter names
				
				if (is("edition")) $p["edition"][0] = preg_replace("~\s+ed(ition)?\.?\s*$~i", "", $p["edition"][0]);
				
				//volume
				if (isset($p["vol"]) && !isset($p["volume"][0])) {$p["volume"] = $p["vol"]; unset($p["vol"]);}
				
				//page nos
				preg_match("~(\w?\w?\d+\w?\w?)(\D+(\w?\w?\d+\w?\w?))?~", $p["pages"][0], $pagenos);
				
				//Authors
				if (isset($p["authors"]) && !isset($p["author"][0])) {$p["author"] = $p["authors"]; unset($p["authors"]);}
				preg_match("~[^.,;\s]{2,}~", $p["author"][0], $firstauthor); 
				if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last"][0], $firstauthor); 
				if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last1"][0], $firstauthor); 
				
				// Is there already a date parameter?
				$dateToStartWith = (isset($p["date"][0]) && !isset($p["year"][0])) ;
				if (!$dateToStartWith && is('origyear')) {
					$p['year'] = $p['origyear'];
					unset ($p['origyear']);
				}
				
				$isbnToStartWith = isset($p["isbn"]);
				if (!isset($p["isbn"][0]) && is("title")) set("isbn", findISBN( $p["title"][0], $p["author"][0] . " " . $p["last"][0] . $p["last1"][0]));
				else echo "\n  Already has an ISBN. ";
				if (!$isbnToStartWith && !$p["isbn"][0]) unset($p["isbn"]);
				
				if (	(is("pages") || is("page"))
							&& is("title")
							&& is("publisher")
							&& (is("date") || is("year"))
							&& (
									is("author") || is("coauthors") || is("others")
									|| is("author1")
									|| is("author1-last")
									|| is("last") || is("last1")
									|| is("editor1-first") || is("editor1-last") || is("editor1")
									|| is("editor") || is("editors")
								)
						)
				 echo "All details present - no need to look up ISBN. "; 
				else {
					if (is("isbn")) getInfoFromISBN();
				}
				
				##############################
				# Finished with citation and retrieved ISBN data #
				#############################
				
				// Now wikify some common formatting errors - i.e. tidy up!
				if (isset($p["title"][0]) && !trim($pStart["title"])) $p["title"][0] = niceTitle($p["title"][0]);
				if (isset($p[$journal][0])) $p[$journal][0] = niceTitle($p[$journal][0], false);
				if (isset($p["periodical"][0])) $p["periodical"][0] = niceTitle($p["periodical"][0], false);
				if (isset($p["pages"][0])) $p["pages"][0] = mb_ereg_replace("([0-9A-Z])[\t ]*(-|\&mdash;|\xe2\x80\x94|\?\?\?)[\t ]*([0-9A-Z])", "\\1\xe2\x80\x93\\3", $p["pages"][0]);
				#if (isset($p["year"][0]) && trim($p["year"][0]) == trim($p["origyear"][0])) unset($p['origyear']);
				#if (isset($p["publisher"][0])) $p["publisher"][0] = truncatePublisher($p["publisher"][0]);
				
				if ($dateToStartWith) unset($p["year"]); // If there was a date parameter to start with, don't add a year too!
				
				// If we have any unused data, check to see if any is redundant!
				if (is("unused_data")){
					$freeDat = explode("|", trim($p["unused_data"][0]));
					unset($p["unused_data"]);
					foreach ($freeDat as $dat) {
						$eraseThis = false;
						foreach ($p as $oP) {
							similar_text(strtolower($oP[0]), strtolower($dat), $percentSim);
							if ($percentSim >= 85) 
								$eraseThis = true;
						}
						if (!$eraseThis) $p["unused_data"][0] .= "|" . $dat;
					}
					if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
					else {
						if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
						echo "\n* <div style=color:limegreen>XXX Unused data in following citation: {$p["unused_data"][0]}</div>";
					}
				}	
				
				//And we're done!
				$endtime = time();
				$timetaken = $endtime - $starttime;
				print "\n  Book reference assessed in $timetaken secs.";
				foreach ($p as $oP){
					$pipe=$oP[1]?$oP[1]:null;
					$equals=$oP[2]?$oP[2]:null;
					if ($pipe) break;
				}
				if (!$pipe) $pipe="\n | ";
				if (!$equals) $equals=" = ";
				foreach($p as $param => $v) {
					if ($param) $cText .= ($v[1]?$v[1]:$pipe ). $param . ($v[2]?$v[2]:$equals) . str_replace(pipePlaceholder, "|", trim($v[0]));
					if (is($param)) $pEnd[$param] = $v[0];
				}
				$p=null;
				if ($pEnd)
					foreach ($pEnd as $param => $value) 
						if (!$pStart[$param]) $additions[$param] = true;
						elseif ($pStart[$param] != $value) $changes[$param] = true;
				$pagecode .=  $citation[$i] . ($cText?"{{{$citation[$i+2]}$cText{$citation[$i+4]}}}":"");
				$cText = null;
				$crossRef = null;
			}
			$pagecode .= $citation[$i]; // Adds any text that comes after the last citation
		}
###################################  START ASSESSING JOURNAL/OTHER CITATIONS ######################################

		if ($citation = preg_split("~{{((\s*[Cc]ite[_ ]?[jJ]ournal(?=\s*\|)|\s*[cC]itation(?=\s*\|))([^{}]|{{.*}})*)([\n\s]*)}}~U", $pagecode, -1, PREG_SPLIT_DELIM_CAPTURE)) {
			$pagecode = null;
			$iLimit = (count($citation)-1); 
			for ($i=0; $i<$iLimit; $i+=5){//Number of brackets in cite journal regexp + 1
				$starttime = time();
				$c = preg_replace("~(?<![\w\d&\?])id(\s*=\s*)(\{\{)?(issn\s*\|?)?\s*(\d[\-\d ]{8,})\s*(?(2)\}\}|)~i","issn$1$4",
					preg_replace("~(?<![\?&]id=)issn\s?:(\s?)(\d\d)~i","issn$1=$1$2",
					preg_replace("~(doi\s*=\s*)doi\s?=\s?(\d\d)~","$1$2",
					preg_replace("~(?<![\?&]id=)doi\s?:(\s?)(\d\d)~","doi$1=$1$2", $citation[$i+1])))); // Replaces doi: with doi = ; issn
				while (preg_match("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", $c)) $c = preg_replace("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", "$1" . pipePlaceholder, $c); // Strips pipes within internal sub-templates
				preg_match(siciRegExp, urldecode($c), $sici);
				// Split citation into parameters
				$parts = preg_split("~([\n\s]*\|[\n\s]*)([\w\d-_]*)(\s*= *)~", $c, -1, PREG_SPLIT_DELIM_CAPTURE);
				$partsLimit = count($parts);
				if (	 strpos($parts[0], "|") > 0
						&& strpos($parts[0],"[[") === FALSE
						&& strpos($parts[0], "{{") === FALSE
						) {
						set("unused_data", substr($parts[0], strpos($parts[0], "|")+1));
						// We'll come back to unused_data later with the useUnusedData() function
				}
				for ($partsI = 1; $partsI <= $partsLimit; $partsI += 4) {
					$value = $parts[$partsI+3];
					$pipePos = strpos($value, "|");
					if ($pipePos > 0 && strpos($value, "[[") === false & strpos($value, "{{") === FALSE) {
						// There are two "parameters" on one line.  One must be missing an equals.
						$p["unused_data"][0] .= " " . substr($value, $pipePos);
						$value = substr($value, 0, $pipePos);
					}
					// Load each line into $p[param][0123]
					$p[strtolower($parts[$partsI+1])] = Array($value, $parts[$partsI], $parts[$partsI+2]); // Param = value, pipe, equals
				}
				if ($p["doix"]){ 
					$p["doi"][0] = str_replace($dotEncode, $dotDecode, $p["doix"][0]);
					unset($p["doix"]);
				}
				//Make a note of how things started so we can give an intelligent edit summary
				foreach($p as $param=>$value)	if (is($param)) $pStart[$param] = $value[0];
				if (is("inventor") || is("inventor-last") || is("patent-number")) print "<p>Unrecognised citation type. Ignoring.</p>";// Don't deal with patents!
				else {	
					$journal = is("periodical")?"periodical":"journal";
					// See if we can use any of the parameters lacking equals signs:
					$freeDat = explode("|", trim($p["unused_data"][0]));
					useUnusedData();
					if (is("isbn")) getInfoFromISBN();
					if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
					else {
						if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
					}
					echo "\n* {$p["title"][0]}";
					// Load missing parameters from SICI, if we found one...
					if ($sici[0]){
						if (!is($journal) && !is("issn")) set("issn", $sici[1]);
						#if (!is ("year") && !is("month") && $sici[3]) set("month", date("M", mktime(0, 0, 0, $sici[3], 1, 2005)));
						if (!is("year")) set("year", $sici[2]);
						#if (!is("day") && is("month") && $sici[4]) set ("day", $sici[4]);
						if (!is("volume")) set("volume", 1*$sici[5]);
						if (!is("issue") && $sici[6]) set("issue", 1*$sici[6]);
						if (!is("pages") && !is("page")) set("pages", 1*$sici[7]);
					}
					// Fix typos in parameter names
					// DOI - urldecode
					if (isset($p["doi"][0])) {
						print urldecode($p['doi'][0]);
						$p["doi"][0] = str_replace($pcEncode,$pcDecode,str_replace(' ', '+', urldecode($p["doi"][0])));
						$noComDoi= preg_replace("~<!--[\s\S]*-->~U", "", $p["doi"][0]);
						if (preg_match("~10\.\d{4}/\S+~", $noComDoi,$match)) set("doi", $match[0]); 
					} else {
						if (preg_match("~10\.\d{4}/[^&\s]*~", urldecode($c), $match)) $p["doi"][0] = $match[0];
					}
					$doiToStartWith = isset($p["doi"]);

					//volume
					if (isset($p["vol"]) && !isset($p["volume"][0])) {$p["volume"] = $p["vol"]; unset($p["vol"]);}
					
					// pmid = PMID 1234 can produce pmpmid = 1234
					if (isset($p["pmpmid"])) {$p["pmid"] = $p["pmpmid"]; unset($p["pmpmid"]);}
					
					//pages
					preg_match("~(\w?\w?\d+\w?\w?)(\D+(\w?\w?\d+\w?\w?))?~", $p["pages"][0], $pagenos);
					
					//Edition - don't want 'Edition ed.'
					if (is("edition")) $p["edition"][0] = preg_replace("~\s+ed(ition)?\.?\s*$~i", "", $p["edition"][0]);
					
					//Authors
					if (isset($p["authors"]) && !isset($p["author"][0])) {$p["author"] = $p["authors"]; unset($p["authors"]);}
					preg_match("~[^.,;\s]{2,}~", $p["author"][0], $firstauthor); 
					if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last"][0], $firstauthor); 
					if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last1"][0], $firstauthor); 
					
					// Is there already a date parameter?
					$dateToStartWith = (isset($p["date"][0]) && !isset($p["year"][0])) ;
					if (!trim($p["doi"][0])) {
										
						//Try CrossRef
						echo "\nChecking CrossRef database... ";
						$crossRef = crossRefDoi(trim($p["title"][0]), trim($p[$journal][0]), trim($firstauthor[0]), trim($p["year"][0]), trim($p["volume"][0]), $pagenos[1], $pagenos[3], trim($p["issn"][0]), trim($p["url"][0]));
						if ($crossRef) {
							echo "Match found!<br>";
							$p["doi"][0] = $crossRef->doi;
							noteDoi($p["doi"][0], "CrossRef");
						} else echo "Failed.<br>";
						//Try URL param
						if (!isset($p["doi"][0]) && !$crossRefOnly) {
							if (strpos($p["url"][0],"http://")!==false) {
								//Try using URL parameter		
								echo $htmlOutput?("Trying <a href=\"" . $p["url"][0] . "\">URL</a>. <br>"):"Trying URL";
								$doi = findDoi($p["url"][0]);
								if ($doi) {
									noteDoi($p["doi"][0], "URL");
									$p["doi"][0] = $doi;
								}
							} else echo "No valid URL specified.  ";
							
							if (!trim($p["doi"][0]) && $searchYahoo) {
								$ident = "\"" . trim($p["title"][0]) . "\" " . trim($p[$journal][0]) . " " . trim($p["author"][0]) . " " . trim($p["coauthors"][0]);
								//Try Yahoo
								$yURL = "http://api.search.yahoo.com/WebSearchService/V1/webSearch?appid=$yAppId&results=15&query=doi+".urlencode($ident);
								$searchLimit = $searchDepth;
								$yI=null;
								echo "Querying <a href='$yURL'>Yahoo API</a> to a depth of $searchLimit with <i>$ident</i><br>";
								$yPage = simplexml_load_file($yURL);
								foreach($yPage->Result as $yResult) {
									$yI++;
									if ($yI > $searchLimit || $p["doi"][0]) break;
									if ($yResult->MimeType == "text/html") {
										$u = $yResult->Url;
										$fsize = file_size($u);
										if ($fsize > 1280000) {
											print "URL abandoned: file size too large ($fsize).<br>";
										} else {
											echo "<small>Trying result</a> #$yI: <a href=$u>$u</a></small>";
											$p["doi"][0] = scrapeDoi($u);
											if ($p["doi"][0]) noteDoi($p["doi"][0], "Yahoo");
										}
									}	
								}
								echo " Yahoo results exhausted.<br>";
								$yPage = null;
							}
							if (!isset($p["doi"][0])) {
								$isbnToStartWith = isset($p["isbn"]);
								if (!isset($p["isbn"][0]) && is("title")) set("isbn", findISBN( $p["title"][0], $p["author"][0] . " " . $p["last"][0] . $p["last1"][0]));
								else echo "\n  Already has an ISBN. ";
								if (!$isbnToStartWith && !$p["isbn"][0]) unset($p["isbn"]); else getInfoFromISBN();
							}
						}
					} else echo "\n  Already has a DOI. ";
					
					if (!$doiToStartWith && !is("doi")) unset($p["doi"]);
					if (	is($journal)
								&& is("volume")
								&& (is("pages") || is("page"))
								&& is("title")
								&& (is("date") || is("year"))
								&& (is("author") ||is("last") || is("last1")
							)
					) echo "\nAll details present - no need to query CrossRef. "; 
					else {
						if (is("doi")) $crossRef = $crossRef?$crossRef:crossRefData(urlencode(trim($p["doi"][0]))); else $crossRef = null;
						
						print "\nQuerying PubMed for article details...";
						//Now let's try PMID.
						$results = (pmSearchResults($p));
						if ($results[1] == 1) {
							$details = pmArticleDetails($results[0]);
							foreach ($details as $key=>$value) if (!is($key)) $p[$key][0] = $value;
							if (!is("url")) {
								$url = pmFullTextUrl($p["pmid"][0]);
								if ($url) {
									set ("url", $url); 
									set ("format", "Free full text");
								}
							}
							
							echo " Found something useful! Looking it up in CrossRef... ";
							
							$crossRef = crossRefDoi(trim($p["title"][0]), trim($p[$journal][0]), trim($firstauthor[0]), trim($p["year"][0]), trim($p["volume"][0]), $pagenos[1], $pagenos[3], trim($p["issn"][0]), trim($p["url"][0]));
							if ($crossRef) {
								echo "Match found!";
								$p["doi"][0] = $crossRef->doi;
							} else echo "No DOI record found.";
						}
						print "... done.</p>";
					}
					#############################
					# Finished with citation and retrieved CrossRef #
					############################
					
					//Now use CrossRef
					if ($crossRef){
						ifNullSet("title", $crossRef->article_title);
						ifNullSet("year", $crossRef->year);
						if (!is("author") && !is("last1") && !is("last") && $crossRef->contributors->contributor) {
							$authors=null;
							foreach ($crossRef->contributors->contributor as $author) $authors .= ($authors?"; ":"") . mb_convert_case($author->surname, MB_CASE_TITLE, "UTF-8") . ", " . $author->given_name;				
							$p["author"][0] = $authors;
							$checkNewData = true;
						} else $checkNewData = false;
						ifNullSet($journal, $crossRef->journal_title);
						ifNullSet("volume", $crossRef->volume);
						if (!is("page")) ifNullSet("pages", $crossRef->first_page);
					} else {$checkNewData = false; echo "\nNo CrossRef record found.\n";}
					
					if (!is("pmid") && $slowMode) {
						if (is("url")) {
							print "<p>Seaching URL for PMID</p>";
							$meta = @get_meta_tags($p["url"][0]);
							$p["pmid"][0] = $meta["citation_pmid"];
							if (!$p["pmid"][0]) unset($p["pmid"]);
						}
						if (!is("pmid")
								&& is("doi")) {
							print "<p>Seaching DOI for PMID</p>";
							$meta = @get_meta_tags("http://dx.doi.org/" . $p["doi"][0]);
							$p["pmid"][0] = $meta["citation_pmid"];
							if (!$p["pmid"][0]) unset($p["pmid"]);
						}
					}
					if ($checkNewData && $slowMode) {
						echo "\n<p> Verifying new data... ";
						if (is("url")) {
							print "Trying to expand citation details from url parameter.<br>";
							$metas = get_all_meta_tags($p["url"][0]);
						} else if (isset($p["doi"][0])) {
							print "Trying to expand citation details from doi parameter<br>";
							$metas = get_all_meta_tags("http://dx.doi.org/" . $p["doi"][0]);
						}
						if (isset($metas["author"])) $p["author"][0] = $metas["author"];
						echo "done.\n";
					}
					if (!is("format") && is("url")){ 
						print "\nDetermining format of URL...";
						$formatSet = isset($p["format"]);
						if (!$p["archiveurl"]) $p["format"][0] = assessUrl($p["url"][0]);
						if (!$formatSet && trim($p["format"][0]) == "") unset($p["format"]);
						echo "Done" , is("format")?" ({$p["format"][0]})":"" , ".</p>";
					}
				}
				// Now wikify some common formatting errors - i.e. tidy up!
				if (!trim($pStart["title"]) && isset($p["title"][0])) $p["title"][0] = formatTitle($p["title"][0]);
				if (isset($p[$journal][0])) $p[$journal][0] = niceTitle($p[$journal][0], false);
				if (isset($p["pages"][0])) $p["pages"][0] = mb_ereg_replace("([0-9A-Z])[\t ]*(-|\&mdash;|\xe2\x80\x94|\?\?\?)[\t ]*([0-9A-Z])", "\\1\xe2\x80\x93\\3", $p["pages"][0]);
				if ($dateToStartWith) unset($p["year"]); // If there was a date parameter to start with, don't add a year too!
				// Check that the DOI functions.	
				if (trim($p["doi"][0]) != "" && trim($p["doi"][0]) != "|" && $slowMode) {
					echo "\nChecking that the DOI is operational...";
					$brokenDoi = isDoiBroken($p["doi"][0], $p);
					if ($brokenDoi && !is("doi_brokendate")) {
						set("doi_brokendate", date("Y-m-d"));
					}
					ELSE if (!$brokenDoi) unset($p["doi_brokendate"]);
					echo $brokenDoi?" It isn't.":"OK!", "</p>";
				}
				/*if (!$p["url"]){
					unset($p["format"]/*, $p["accessdate"], $p["accessyear"], $p["accessmonthday"], $p["accessmonth"], $p["accessday"]);
				}elseif (!$p["url"][0]){
					unset($p["format"][0]/*, $p["accessdate"][0], $p["accessyear"][0], $p["accessmonthday"], $p["accessmonth"][0], $p["accessday"][0]);
				}*/
				
				//DOIlabel is now redundant
				unset($p["doilabel"]);
				
				//because of cite journal doc...
				//if (is("doi")) unset($p["issn"]);
				if (is($p["journal"]) && (is("doi") || is("issn"))) unset($p["publisher"]);
				
				// If we have any unused data, check to see if any is redundant!
				if (is("unused_data")){
					$freeDat = explode("|", trim($p["unused_data"][0]));
					unset($p["unused_data"]);
					foreach ($freeDat as $dat) {
						$eraseThis = false;
						foreach ($p as $oP) {
							similar_text(strtolower($oP[0]), strtolower($dat), $percentSim);
							if ($percentSim >= 85) 
								$eraseThis = true;
						}
						if (!$eraseThis) $p["unused_data"][0] .= "|" . $dat;
					}
					if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
					else {
						if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
						echo "\nXXX Unused data in following citation: {$p["unused_data"][0]}";
					}
				}	
				
				//And we're done!
				$endtime = time();
				$timetaken = $endtime - $starttime;
				print "<small>Citation assessed in $timetaken secs.</small><br>";
				foreach ($p as $oP){
					$pipe=$oP[1]?$oP[1]:null;
					$equals=$oP[2]?$oP[2]:null;
					if ($pipe) break;
				}
				if (!$pipe) $pipe="\n | ";
				if (!$equals) $equals=" = ";
				foreach($p as $param => $v) {
					if ($param) $cText .= ($v[1]?$v[1]:$pipe ). $param . ($v[2]?$v[2]:$equals) . str_replace(pipePlaceholder, "|", trim($v[0]));
					if (is($param)) $pEnd[$param] = $v[0];
				}
				$p=null;
				if ($pEnd)
					foreach ($pEnd as $param => $value) 
						if (!$pStart[$param]) $additions[$param] = true;
						elseif ($pStart[$param] != $value) $changes[$param] = true;
				$pagecode .=  $citation[$i] . ($cText?"{{{$citation[$i+2]}$cText{$citation[$i+4]}}}":"");
				$cText = null;
				$crossRef = null;
			}
			
			$pagecode .= $citation[$i]; // Adds any text that comes after the last citation
		}
		
###################################  Cite arXiv ######################################
		if ($citation = preg_split("~{{((\s*[Cc]ite[_ ]?[aA]r[xX]iv(?=\s*\|))([^{}]|{{.*}})*)([\n\s]*)}}~U", $pagecode, -1, PREG_SPLIT_DELIM_CAPTURE)) {
			$pagecode = null;
			$iLimit = (count($citation)-1); 
			for ($i=0; $i<$iLimit; $i+=5){//Number of brackets in cite arXiv regexp + 1
				$starttime = time();
				$c = $citation[$i+1]; 
				while (preg_match("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", $c)) $c = preg_replace("~(?<=\{\{)([^\{\}]*)\|(?=[^\{\}]*\}\})~", "$1" . pipePlaceholder, $c);
				// Split citation into parameters
				$parts = preg_split("~([\n\s]*\|[\n\s]*)([\w\d-_]*)(\s*= *)~", $c, -1, PREG_SPLIT_DELIM_CAPTURE);
				$partsLimit = count($parts);
				if (strpos($parts[0], "|") >0 && strpos($parts[0],"[[") === FALSE && strpos($parts[0], "{{") === FALSE) set("unused_data", substr($parts[0], strpos($parts[0], "|")+1));
				for ($partsI=1; $partsI<=$partsLimit; $partsI+=4) {
					$value = $parts[$partsI+3];
					$pipePos = strpos($value, "|");
					if ($pipePos > 0 && strpos($value, "[[") === false & strpos($value, "{{") === FALSE) {
						// There are two "parameters" on one line.  One must be missing an equals.
						$p["unused_data"][0] .= " " . substr($value, $pipePos);
						$value = substr($value, 0, $pipePos);
					}
					// Load each line into $p[param][0123]
					$p[strtolower($parts[$partsI+1])] = Array($value, $parts[$partsI], $parts[$partsI+2]); // Param = value, pipe, equals
				}
				//Make a note of how things started so we can give an intelligent edit summary
				foreach($p as $param=>$value)	if (is($param)) $pStart[$param] = $value[0];
				// See if we can use any of the parameters lacking equals signs:
				$freeDat = explode("|", trim($p["unused_data"][0]));
				useUnusedData();
				if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
				else if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
				
				echo "\n* {$p["title"][0]}";
				// Fix typos in parameter names
				
				//Authors
				if (isset($p["authors"]) && !isset($p["author"][0])) {$p["author"] = $p["authors"]; unset($p["authors"]);}
				preg_match("~[^.,;\s]{2,}~", $p["author"][0], $firstauthor); 
				if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last"][0], $firstauthor); 
				if (!$firstauthor[0]) preg_match("~[^.,;\s]{2,}~", $p["last1"][0], $firstauthor); 
				
				// Is there already a date parameter?
				$dateToStartWith = (isset($p["date"][0]) && !isset($p["year"][0])) ;
				print $p["eprint"][0] . "\n";
				if (is("eprint")
						&& !(is("title") && is("author") && is("year") && is("version"))) 
						getDataFromArxiv($p["eprint"][0]);
				echo 7;
				
				// Now wikify some common formatting errors - i.e. tidy up!
				if (!trim($pStart["title"]) && isset($p["title"][0])) $p["title"][0] = formatTitle($p["title"][0]);
				
				// If we have any unused data, check to see if any is redundant!
				if (is("unused_data")){
					$freeDat = explode("|", trim($p["unused_data"][0]));
					unset($p["unused_data"]);
					foreach ($freeDat as $dat) {
						$eraseThis = false;
						foreach ($p as $oP) {
							similar_text(strtolower($oP[0]), strtolower($dat), $percentSim);
							if ($percentSim >= 85) 
								$eraseThis = true;
						}
						if (!$eraseThis) $p["unused_data"][0] .= "|" . $dat;
					}
					if (trim(str_replace("|", "", $p["unused_data"][0])) == "") unset($p["unused_data"]); 
					else {
						if (substr(trim($p["unused_data"][0]), 0, 1) == "|") $p["unused_data"][0] = substr(trim($p["unused_data"][0]), 1);
						echo "\nXXX Unused data in following citation: {$p["unused_data"][0]}";
					}
				}

				// Now: Citation bot task 5.  If there's a journal parameter switch the citation to 'cite journal'.
				$changeToJournal = is('journal');
				if ($changeToJournal && is('eprint')) {
					$p['id'][0] = "{{arXiv|{$p['eprint'][0]}}}";
					unset($p['class']);
					unset($p['eprint']);
					$changeCiteType = true;
				}
				
				//And we're done!
				$endtime = time();
				$timetaken = $endtime - $starttime;
				print "* Citation assessed in $timetaken secs. " . ($changeToJournal?"Changing to Cite Journal. ":"Keeping as cite arXiv") . "\n";
				foreach ($p as $oP){
					$pipe=$oP[1]?$oP[1]:null;
					$equals=$oP[2]?$oP[2]:null;
					if ($pipe) break;
				}
				if (!$pipe) $pipe="\n | ";
				if (!$equals) $equals=" = ";
				foreach($p as $param => $v) {
					if ($param) $cText .= ($v[1]?$v[1]:$pipe ). $param . ($v[2]?$v[2]:$equals) . str_replace(pipePlaceholder, "|", trim($v[0]));
					if (is($param)) $pEnd[$param] = $v[0];
				}
				$p=null;
				if ($pEnd)
					foreach ($pEnd as $param => $value) 
						if (!$pStart[$param]) $additions[$param] = true;
						elseif ($pStart[$param] != $value) $changes[$param] = true;
				$pagecode .=  $citation[$i] . ($cText?"{{" . ($changeToJournal?"cite journal":$citation[$i+2]) . "$cText{$citation[$i+4]}}}":"");
#				$pagecode .=  $citation[$i] . ($cText?"{{{$citation[$i+2]}$cText{$citation[$i+4]}}}":"");
				$cText = null;
				$crossRef = null;
			}
			
			$pagecode .= $citation[$i]; // Adds any text that comes after the last citation
		}
		if (trim($pagecode)){
			if (strtolower($pagecode) != strtolower($startcode)) {
				if ($additions){
					$smartSum = "Added: ";
					foreach ($additions as $param=>$v)	{$smartSum .= "$param, "; unset($changes[$param]);}
					$smartSum = substr($smartSum, 0, strlen($smartSum)-2);
					$smartSum .= ". ";
				}
				if ($changes["accessdate"]) {
					$smarSum .= "Removed accessdate with no specified URL. "; 
					unset($changes["accessdate"]);
				}
				if ($changes) {
					$smartSum .= "Formatted: ";
					foreach ($changes as $param=>$v)	$smartSum .= 				"$param, ";
					$smartSum = substr($smartSum,0, strlen($smartSum)-2);
					$smartSum.=". ";
				}
				if ($changeCiteType) $smartSum .= "Changed citation types. ";
				if (!$smartSum) $smartSum = "Removed redundant parameters. ";
				echo "\n$smartSum\n";
				$editSummary = $editSummaryStart . $editInitiator . $smartSum . $editSummaryEnd;
				if ($ON) {
					if ( strpos($page, "andbox")>1) {
							echo $htmlOutput?"<i style='color:red'>Writing to <a href=\"http://en.wikipedia.org/w/index.php?title=".urlencode($page)."\">$page</a> <small><a href=http://en.wikipedia.org/w/index.php?title=".urlencode($page)."&action=history>history</a></small></i>\n\n</br><br>":"\n*** Writing to $page"; 
							write($page . $_GET["subpage"], $pagecode, "Citation maintenance: Fixing/testing bugs. " 
								.	"Problems? [[User_talk:Smith609|Contact the bot's operator]]. ");
						}else{
							echo "<i style='color:red'>Writing to <a href=\"http://en.wikipedia.org/w/index.php?title=".urlencode($page)."\">$page</a> ... "; 
							if (write($page . $_GET["subpage"], $pagecode, $editSummary)) {
								updateBacklog($page);
								echo "Success.";
							} else {
								echo "Edit may have failed. Retrying: <span style='font-size:1px'>xxx</span> ";
								if (write($page . $_GET["subpage"], $pagecode, $editSummary)) {
									updateBacklog($page);
									echo "Success.";
								} else {
									echo "Still no good. One last try: ";
									if (write($page . $_GET["subpage"], $pagecode, $editSummary)) {
										updateBacklog($page);
										echo "Success. Phew!";
									} else echo "Failed.  Abandoning page.";
								}
							}
							echo $htmlOutput?" <small><a href=http://en.wikipedia.org/w/index.php?title=".urlencode($page)."&action=history>history</a></small></i>\n\n<br>":".";
						}
						$page = nextPage();
						$pageDoneIn = time() - $startPage;
						if ($pageDoneIn<3) {echo "That was quick! ($pageDoneIn secs.) I think I'd better catch my breath."; sleep(3);} else echo "<i>Page took $pageDoneIn secs to process.</i>";
				} else {
					echo "\n\n\n<h5>Output</h5>\n\n\n<!--New code:--><pre>\n\n\n" . $pagecode . "\n\n\n</pre><!--DONE!-->\n\n\n<p><b>Bot switched off</b> &rArr; no edit made.<br><b>Changes:</b> <i>$smartSum</i></p>";
					$page = false;
				}
				
				//Unset smart edit summary parameters
				$pStart = null; $pEnd = null; $additions=null;$changes=null; $smartSum = null;
			} else {
				echo "<b>No changes required</b> &rArr; no edit made.";
				updateBacklog($page);
				$page = $ON?nextPage():null;
			}
		} else {
			if (trim($startcode)=='') {
				echo "<b>Blank page.</b> Perhaps it's been deleted?";
				updateBacklog($page);
				$page = nextPage();
			}
			else {
				echo "<b>Error:</b> Blank page produced. This bug has been reported. Page content: $startcode";
				mail ("MartinS+doibot@gmail.com", "DOI BOT ERROR", "Blank page produced.\n[Page = $page]\n[SmartSum = $smartSum ]\n[\$citation = ". print_r($citation, 1) . "]\n[Request variables = ".print_r($_REQUEST, 1) . "]\n\nError message generated by doibot.php.");
				$page = null;
				exit; #legit
			}
		}
	}
	$urlsTried = null; //Clear some memory
	
	// These variables should change after the first edit
	$isbnKey = "3TUCZUGQ"; //This way we shouldn't exhaust theISBN key for on-demand users.
	$isbnKey2 = "RISPMHTS"; //This way we shouldn't exhaust theISBN key for on-demand users.
	$editSummaryEnd = " You can [[WP:UCB|use this bot]] yourself! Please [[User:DOI_bot/bugs|report any bugs]].";
}