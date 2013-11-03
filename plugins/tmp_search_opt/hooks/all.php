<?php

#
# Experimental replacement for do_search based on a new approach using unions
#
#
#

if (!function_exists("do_search")) {
function do_search($search,$restypes="",$order_by="relevance",$archive=0,$fetchrows=-1,$sort="desc",$access_override=false,$starsearch=0,$ignore_filters=false,$return_disk_usage=false,$recent_search_daylimit="", $go=false)
	{	
	debug("search=$search $go $fetchrows restypes=$restypes archive=$archive daylimit=$recent_search_daylimit");
	
	# globals needed for hooks	 
	global $sql,$order,$select,$sql_join,$sql_filter,$orig_order,$checkbox_and,$collections_omit_archived,$search_sql_double_pass_mode, $usergroup;

	$alternativeresults = hook("alternativeresults", "", array($go));
	if ($alternativeresults) {return $alternativeresults; }
	
	$modifyfetchrows = hook("modifyfetchrows", "", array($fetchrows));
	if ($modifyfetchrows) {$fetchrows=$modifyfetchrows; }
	
    
	# Takes a search string $search, as provided by the user, and returns a results set
	# of matching resources.
	# If there are no matches, instead returns an array of suggested searches.
	# $restypes is optionally used to specify which resource types to search.
	# $access_override is used by smart collections, so that all all applicable resources can be judged regardless of the final access-based results
	
	# resolve $order_by to something meaningful in sql
	$orig_order=$order_by;
	global $date_field;
	$order=array("relevance"=>"score $sort, user_rating $sort, hit_count $sort, field$date_field $sort,r.ref $sort","popularity"=>"user_rating $sort,hit_count $sort,field$date_field $sort,r.ref $sort","rating"=>"r.rating $sort, user_rating $sort, score $sort,r.ref $sort","date"=>"field$date_field $sort,r.ref $sort","colour"=>"has_image $sort,image_blue $sort,image_green $sort,image_red $sort,field$date_field $sort,r.ref $sort","country"=>"country $sort,r.ref $sort","title"=>"title $sort,r.ref $sort","file_path"=>"file_path $sort,r.ref $sort","resourceid"=>"r.ref $sort","resourcetype"=>"resource_type $sort,r.ref $sort","titleandcountry"=>"title $sort,country $sort","random"=>"RAND()");
	if (!in_array($order_by,$order)&&(substr($order_by,0,5)=="field") ) {
		if (!is_numeric(str_replace("field","",$order_by))) {exit("Order field incorrect.");}
		$order[$order_by]="$order_by $sort";
		}

	hook("modifyorderarray");


	# Recognise a quoted search, which is a search for an exact string
	$quoted_string=false;
	if (substr($search,0,1)=="\"" && substr($search,-1,1)=="\"") {$quoted_string=true;$search=substr($search,1,-1);}


	$order_by=$order[$order_by];
	$keywords=split_keywords($search);
	$search=trim($search);

	$modified_keywords=hook('dosearchmodifykeywords', '', array($keywords));
	if ($modified_keywords)
		$keywords=$modified_keywords;

	# -- Build up filter SQL that will be used for all queries

	$sql_filter="";
        
        $sql_keyword_union_whichkeys=array();
        $sql_keyword_union=array();
        $sql_keyword_union_aggregation=array();
        $sql_keyword_union_criteria=array();
        
	# append resource type filtering

	if (($restypes!="")&&(substr($restypes,0,6)!="Global"))
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$restypes_x=explode(",",$restypes);
		$sql_filter.="resource_type in ('" . join("','",$restypes_x) . "')";
		}
	
	if ($starsearch!="" && $starsearch!=0)
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="user_rating >= '$starsearch'";
		}	
	
	if($recent_search_daylimit!="")
			{
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.= "creation_date > (curdate() - interval " . $recent_search_daylimit . " DAY)";
			}

	# The ability to restrict access by the user that created the resource.
	global $resource_created_by_filter;
	if (isset($resource_created_by_filter) && count($resource_created_by_filter)>0)
	 		{
	 		$created_filter="";
	 		foreach ($resource_created_by_filter as $filter_user)
	 			{
	 			if ($filter_user==-1) {global $userref;$filter_user=$userref;} # '-1' can be used as an alias to the current user. I.e. they can only see their own resources in search results.
				if ($created_filter!="") {$created_filter.=" or ";}	
				$created_filter.= "created_by = '" . $filter_user . "'";
	 			}	 
	 		if ($created_filter!="")
	 				{
	 				if ($sql_filter!="") {$sql_filter.=" and ";}			
	 				$sql_filter.="(" . $created_filter . ")";
	 				}
	 		}


	# Geo zone exclusion
	# A list of upper/lower long/lat bounds, defining areas that will be excluded from geo search results.
	# Areas are defined as southwest lat, southwest long, northeast lat, northeast long
	global $geo_search_restrict;	
	if (count($geo_search_restrict)>0 && substr($search,0,4)=="!geo")
		{
		foreach ($geo_search_restrict	as $zone)
			{
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.= "(geo_lat is null or geo_long is null or not(geo_lat >= '" . $zone[0] . "' and geo_lat<= '" . $zone[2] . "'";
			$sql_filter.= "and geo_long >= '" . $zone[1] . "' and geo_long<= '" . $zone[3] . "'))";
			}
		}

	# If returning disk used by the resources in the search results ($return_disk_usage=true) then wrap the returned SQL in an outer query that sums disk usage.
	$sql_prefix="";$sql_suffix="";
	if ($return_disk_usage) {$sql_prefix="select sum(disk_usage) total_disk_usage,count(*) total_resources from (";$sql_suffix=") resourcelist";}

	# append resource type restrictions based on 'T' permission	
	# look for all 'T' permissions and append to the SQL filter.
	global $userpermissions;
	$rtfilter=array();
	for ($n=0;$n<count($userpermissions);$n++)
		{
		if (substr($userpermissions[$n],0,1)=="T")
			{
			$rt=substr($userpermissions[$n],1);
			if (is_numeric($rt)&&!$access_override) {$rtfilter[]=$rt;}
			}
		}
	if (count($rtfilter)>0)
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="resource_type not in (" . join(",",$rtfilter) . ")";
		}
	
	# append "use" access rights, do not show restricted resources unless admin
	if (!checkperm("v")&&!$access_override)
		{
		if ($sql_filter!="") {$sql_filter.=" and ";}
		$sql_filter.="r.access<>'2'";
		}
		
	# append archive searching (don't do this for collections or !listall, archived resources can still appear in these searches)
	if ( (substr($search,0,8)!="!listall" && substr($search,0,11)!="!collection") || ($collections_omit_archived && !checkperm("e2")))
		{
		global $pending_review_visible_to_all;
		if ($archive==0 && $pending_review_visible_to_all)
			{
			# If resources pending review are visible to all, when listing only active resources include
			# pending review (-1) resources too.
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.="(archive='0' or archive=-1)";
			}
		else
			{
			# Append normal filtering.
			if ($sql_filter!="") {$sql_filter.=" and ";}
			$sql_filter.="archive='$archive'";
			global $userref, $pending_submission_searchable_to_all;
			if (!$pending_submission_searchable_to_all&&($archive=="-2")&&!(checkperm("e-2")&&checkperm("t"))) $sql_filter.=" and created_by='" . $userref . "'";
			}
		}
	
	
	# append ref filter - never return the batch upload template (negative refs)
	if ($sql_filter!="") {$sql_filter.=" and ";}
	$sql_filter.="r.ref>0";
	
	# ------ Advanced 'custom' permissions, need to join to access table.
	$sql_join="";
	global $k;
	if ((!checkperm("v")) &&!$access_override)
		{
		global $usergroup;global $userref;
		# one extra join (rca2) is required for user specific permissions (enabling more intelligent watermarks in search view)
		# the original join is used to gather group access into the search query as well.
		$sql_join=" left outer join resource_custom_access rca2 on r.ref=rca2.resource and rca2.user='$userref'  and (rca2.user_expires is null or rca2.user_expires>now()) and rca2.access<>2  ";	
		$sql_join.=" left outer join resource_custom_access rca on r.ref=rca.resource and rca.usergroup='$usergroup' and rca.access<>2 ";
		
		if ($sql_filter!="") {$sql_filter.=" and ";}
		# If rca.resource is null, then no matching custom access record was found
		# If r.access is also 3 (custom) then the user is not allowed access to this resource.
		# Note that it's normal for null to be returned if this is a resource with non custom permissions (r.access<>3).
		$sql_filter.=" not(rca.resource is null and r.access=3)";
		}
		
	# Join thumbs_display_fields to resource table 	
	$select="r.ref, r.resource_type, r.has_image, r.is_transcoding, r.hit_count, r.creation_date, r.rating, r.user_rating, r.user_rating_count, r.user_rating_total, r.file_extension, r.preview_extension, r.image_red, r.image_green, r.image_blue, r.thumb_width, r.thumb_height, r.archive, r.access, r.colour_key, r.created_by, r.file_modified, r.file_checksum, r.request_count, r.new_hit_count, r.expiry_notification_sent, r.preview_tweaks, r.file_path ";	
	
	$modified_select=hook("modifyselect");
	if ($modified_select){$select.=$modified_select;}	
	$modified_select2=hook("modifyselect2");
	if ($modified_select2){$select.=$modified_select2;}	

	# Return disk usage for each resource if returning sum of disk usage.
	if ($return_disk_usage) {$select.=",r.disk_usage";}

	# select group and user access rights if available, otherwise select null values so columns can still be used regardless
	# this makes group and user specific access available in the basic search query, which can then be passed through access functions
	# in order to eliminate many single queries.
	if ((!checkperm("v")) &&!$access_override)
		{
		$select.=",rca.access group_access,rca2.access user_access ";
		}
	else {
		$select.=",null group_access, null user_access ";
	}
	
	# add 'joins' to select (adding them 
	$joins=get_resource_table_joins();
	foreach( $joins as $datajoin)
		{
		$select.=",r.field".$datajoin." ";
		}	

	# Prepare SQL to add join table for all provided keywods
	
	$suggested=$keywords; # a suggested search
	$fullmatch=true;
	$c=0;$t="";$t2="";$score="";
	
	$keysearch=true;
	
	 # Do not process if a numeric search is provided (resource ID)
	global $config_search_for_number, $category_tree_search_use_and;
	if ($config_search_for_number && is_numeric($search)) {$keysearch=false;}
	
	
	# Fetch a list of fields that are not available to the user - these must be omitted from the search.
	$hidden_indexed_fields=get_hidden_indexed_fields();

	
	if ($keysearch)
		{
		for ($n=0;$n<count($keywords);$n++)
			{			
			$keyword=$keywords[$n];
			
			if (substr($keyword,0,1)!="!")
				{
				global $date_field;
				$field=0;#echo "<li>$keyword<br/>";
				if (strpos($keyword,":")!==false && !$ignore_filters)
					{
					$kw=explode(":",$keyword,2);
					global $datefieldinfo_cache;
					if (isset($datefieldinfo_cache[$kw[0]])){
						$datefieldinfo=$datefieldinfo_cache[$kw[0]];
					} else {
						$datefieldinfo=sql_query("select ref from resource_type_field where name='" . escape_check($kw[0]) . "' and type IN (4,6,10)",0);
						$datefieldinfo_cache[$kw[0]]=$datefieldinfo;
					}
					if (count($datefieldinfo))
						{
						$c++;
						$datefieldinfo=$datefieldinfo[0];
						$datefield=$datefieldinfo["ref"];
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$val=str_replace("n","_", $kw[1]);
						$val=str_replace("|","-", $val);
						$sql_filter.="rd" . $c . ".value like '". $val . "%' "; 
						$sql_join.=" join resource_data rd" . $c . " on rd" . $c . ".resource=r.ref and rd" . $c . ".resource_type_field='" . $datefield . "'";
						}

					elseif ($kw[0]=="day")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '____-__-" . $kw[1] . "%' ";
						}
					elseif ($kw[0]=="month")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '____-" . $kw[1] . "%' ";
						}
					elseif ($kw[0]=="year")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field like '" . $kw[1] . "%' ";
						}
					elseif ($kw[0]=="startdate")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field >= '" . $kw[1] . "' ";
						}
					elseif ($kw[0]=="enddate")
						{
						if ($sql_filter!="") {$sql_filter.=" and ";}
						$sql_filter.="r.field$date_field <= '" . $kw[1] . " 23:59:59' ";
						}
					# Additional date range filtering
					elseif (substr($kw[0],0,5)=="range")
						{
						$c++;
						$rangefield=substr($kw[0],6);
						$daterange=false;
						if (strpos($kw[1],"start")!==FALSE )
							{
							$rangestart=str_replace(" ","-",$kw[1]);
							if ($sql_filter!="") {$sql_filter.=" and ";}
							$sql_filter.="rd" . $c . ".value >= '" . substr($rangestart,strpos($rangestart,"start")+5,10) . "'";
							}
						if (strpos($kw[1],"end")!==FALSE )
							{
							$rangeend=str_replace(" ","-",$kw[1]);
							if ($sql_filter!="") {$sql_filter.=" and ";}
							$sql_filter.="rd" . $c . ".value <= '" . substr($rangeend,strpos($rangeend,"end")+3,10) . " 23:59:59'";
							}
						$sql_join.=" join resource_data rd" . $c . " on rd" . $c . ".resource=r.ref and rd" . $c . ".resource_type_field='" . $rangefield . "'";
						}
					else
						{
						$ckeywords=explode(";",$kw[1]);

						# Fetch field info
						global $fieldinfo_cache;
						if (isset($fieldinfo_cache[$kw[0]])){
							$fieldinfo=$fieldinfo_cache[$kw[0]];
						} else {
							$fieldinfo=sql_query("select ref,type from resource_type_field where name='" . escape_check($kw[0]) . "'",0);
							$fieldinfo_cache[$kw[0]]=$fieldinfo;
						}
						if (count($fieldinfo)==0)
							{
							debug("Field short name not found.");return false;
							}
						elseif (in_array($fieldinfo[0]["ref"], $hidden_indexed_fields))
							{
							# Attempt to directly search field that the user does not have access to.
							return false;
							}
							{
							$fieldinfo=$fieldinfo[0];
							}
						$field=$fieldinfo["ref"];

						# Special handling for dates
						if ($fieldinfo["type"]==4 || $fieldinfo["type"]==6 || $fieldinfo["type"]==10) 
							{
							$ckeywords=array(str_replace(" ","-",$kw[1]));
							}



						#special SQL generation for category trees to use AND instead of OR
						if(
							($fieldinfo["type"] == 7 && $category_tree_search_use_and)
						||
							($fieldinfo["type"] == 2 && $checkbox_and)
						) {
							for ($m=0;$m<count($ckeywords);$m++) {
								$keyref=resolve_keyword($ckeywords[$m]);
								if (!($keyref===false)) {
									$c++;

									# Add related keywords
									$related=get_related_keywords($keyref);
									$relatedsql="";
									for ($r=0;$r<count($related);$r++)
										{
										$relatedsql.=" or k" . $c . ".keyword='" . $related[$r] . "'";
										}
									# Form join
									//$sql_join.=" join (SELECT distinct k".$c.".resource,k".$c.".hit_count from resource_keyword k".$c." where k".$c.".keyword='$keyref' $relatedsql) t".$c." ";
									$sql_join.=" join resource_keyword k" . $c . " on k" . $c . ".resource=r.ref and k" . $c . ".resource_type_field='" . $field . "' and (k" . $c . ".keyword='$keyref' $relatedsql)";

									if ($score!="") {$score.="+";}
									$score.="k" . $c . ".hit_count";

									# Log this
									daily_stat("Keyword usage",$keyref);
								}							
								
            						}
						} else {
							$c++;
                     
							# work through all options in an OR approach for multiple selects on the same field
							$searchkeys=array();
							for ($m=0;$m<count($ckeywords);$m++)
								{
								$keyref=resolve_keyword($ckeywords[$m]);
								if ($keyref===false) {$keyref=-1;}
								
                                                                $searchkeys[]=$keyref;
				
								# Also add related.
								$related=get_related_keywords($keyref);
                                                                for ($o=0;$o<count($related);$o++)
									{
									$searchkeys[]=$related[$o];
									}
									
								# Log this
								daily_stat("Keyword usage",$keyref);
								}
	
                                                        
                                                        
                                                                                                                                        $union="select resource,";
                                                                                for ($p=1;$p<=count($keywords);$p++)
                                                                                    {
                                                                                    if ($p==$c) {$union.="true";} else {$union.="false";}
                                                                                    $union.=" as keyword_" . $p . "_found,";
                                                                                    }
                                                                                $union.="hit_count as score from resource_keyword k" . $c . " where (k" . $c . ".keyword='$keyref' or k" . $c . ".keyword in ('" . join("','",$searchkeys) . "')) and k" . $c . ".resource_type_field='" . $field . "'";
                                                                                
                                                                                 if (!empty($sql_exclude_fields)) 
						                	{
                                                                        $union.=" and k" . $c . ".resource_type_field not in (". $sql_exclude_fields .")";
							                }

                                                                                                                                                                                                if (count($hidden_indexed_fields)>0)
                                                                                                                        {
                                                                                                                        $union.=" and k" . $c . ".resource_type_field not in ('". join("','",$hidden_indexed_fields) ."')";	                        
                                                                                                                        }

                                                                        
                                                                        $sql_keyword_union_aggregation[]="bit_or(keyword_" . $c . "_found) as keyword_" . $c . "_found";
                                                                        $sql_keyword_union_criteria[]="h.keyword_" . $c . "_found";
                                                                                
                                                                        $sql_keyword_union[]=$union;
                                                        
                                                        
                                                        
							}
						}
					}
				else
					{

					# Normal keyword (not tied to a field) - searches all fields that the user has access to
					
					# If ignoring field specifications then remove them.
					if (strpos($keyword,":")!==false && $ignore_filters)
						{
						$s=explode(":",$keyword);$keyword=$s[1];
						}

					# Omit resources containing this keyword?
					$omit=false;
					if (substr($keyword,0,1)=="-") {$omit=true;$keyword=substr($keyword,1);}
					
					
					
					global $noadd, $wildcard_always_applied;
					if (in_array($keyword,$noadd)) # skip common words that are excluded from indexing
						{
						$skipped_last=true;
						}
					else
						{
						# Handle wildcards
						$wildcards=array();
                                                if (strpos($keyword,"*")!==false || $wildcard_always_applied)
							{
							if ($wildcard_always_applied && strpos($keyword,"*")===false)
								{
								# Suffix asterisk if none supplied and using $wildcard_always_applied mode.
								$keyword=$keyword."*";
								}
							
							# Keyword contains a wildcard. Expand.
							global $wildcard_expand_limit;
							$wildcards=sql_array("select ref value from keyword where keyword like '" . escape_check(str_replace("*","%",$keyword)) . "' order by hit_count desc limit " . $wildcard_expand_limit);
                                                        }		

                                                $keyref=resolve_keyword($keyword); # Resolve keyword. Ignore any wildcards when resolving. We need wildcards to be present later but not here.
                                                if ($keyref===false && !$omit && count($wildcards)==0)
                                                        {
                                                        $fullmatch=false;
                                                        $soundex=resolve_soundex($keyword);
                                                        if ($soundex===false)
                                                                {
                                                                # No keyword match, and no keywords sound like this word. Suggest dropping this word.
                                                                $suggested[$n]="";
                                                                }
                                                        else
                                                                {
                                                                # No keyword match, but there's a word that sounds like this word. Suggest this word instead.
                                                                $suggested[$n]="<i>" . $soundex . "</i>";
                                                                }
                                                        }
                                                else
                                                        {
                                                        # Key match, add to query.
                                                        $c++;

                                                        # Add related keywords
                                                        $related=get_related_keywords($keyref);
                                                        
                                                        # Merge wildcard expansion with related keywords
                                                        $related=array_merge($related,$wildcards);
                                                        $relatedsql="";
                                                        if (count($related)>0)
                                                            {
                                                            $relatedsql=" or k" . $c . ".keyword IN ('" . join ("','",$related) . "')";
                                                            }

                                                                
                                                        # Form join
                                                        $sql_exclude_fields = hook("excludefieldsfromkeywordsearch");
                                                        
                                                        
                                                                # Quoted string support
                                                                # TO DO - rewrite to use additional joins
                                                                $positionsql="";
                                                                if ($quoted_string)
                                                                        {
                                                                        if ($c>1)
                                                                                {
                                                                                $last_key_offset=1;
                                                                                if (isset($skipped_last) && $skipped_last) {$last_key_offset=2;} # Support skipped keywords - if the last keyword was skipped (listed in $noadd), increase the allowed position from the previous keyword. Useful for quoted searches that contain $noadd words, e.g. "black and white" where "and" is a skipped keyword.
                                                                                $positionsql="and k" . $c . ".position=k" . ($c-1) . ".position+" . $last_key_offset;
                                                                                }								
                                                                        }



                                                                if (!$omit)
                                                                        {
                                                                        # Include in query
                                                                        
                                                                        $union="select resource,";
                                                                        for ($p=1;$p<=count($keywords);$p++)
                                                                            {
                                                                            if ($p==$c) {$union.="true";} else {$union.="false";}
                                                                            $union.=" as keyword_" . $p . "_found,";
                                                                            }
                                                                        $union.="hit_count as score from resource_keyword k" . $c . " where (k" . $c . ".keyword='$keyref' $relatedsql)";
                                                                        
                                                                         if (!empty($sql_exclude_fields)) 
                                                                {
                                                                $union.=" and k" . $c . ".resource_type_field not in (". $sql_exclude_fields .")";
                                                                }

                                                                                                                                                                                        if (count($hidden_indexed_fields)>0)
                                                                                                                {
                                                                                                                $union.=" and k" . $c . ".resource_type_field not in ('". join("','",$hidden_indexed_fields) ."')";	                        
                                                                                                                }

                                                                
                                                                $sql_keyword_union_aggregation[]="bit_or(keyword_" . $c . "_found) as keyword_" . $c . "_found";
                                                                $sql_keyword_union_criteria[]="h.keyword_" . $c . "_found";
                                                                        
                                                                        $sql_keyword_union[]=$union;                                                                                
                                                                        }
                                                                else
                                                                        {
                                                                        # Exclude matching resources from query (omit feature)
                                                                        if ($sql_filter!="") {$sql_filter.=" and ";}
                                                                        $sql_filter .= "r.ref not in (select resource from resource_keyword where keyword='$keyref')"; # Filter out resources that do contain the keyword.
                                                                        }						                
                                                        
                                                                
                                                        
                                                        # Log this
                                                        daily_stat("Keyword usage",$keyref);
                                                        }
                                                }
                                        $skipped_last=false;
                                        }
					
				}
			}
		}
	# Could not match on provided keywords? Attempt to return some suggestions.
	if ($fullmatch==false)
		{
		if ($suggested==$keywords)
			{
			# Nothing different to suggest.
			debug("No alternative keywords to suggest.");
			return "";
			}
		else
			{
			# Suggest alternative spellings/sound-a-likes
			$suggest="";
			if (strpos($search,",")===false) {$suggestjoin=" ";} else {$suggestjoin=", ";}
			for ($n=0;$n<count($suggested);$n++)
				{
				if ($suggested[$n]!="")
					{
					if ($suggest!="") {$suggest.=$suggestjoin;}
					$suggest.=$suggested[$n];
					}
				}
			debug ("Suggesting $suggest");
			return $suggest;
			}
		}
	# Some useful debug.
	#echo("keywordjoin=" . $sql_join);
	#echo("<br>Filter=" . $sql_filter);
	#echo("<br>Search=" . $search);
        hook("additionalsqlfilter");
        hook("parametricsqlfilter", '', array($search));
	
	# ------ Search filtering: If search_filter is specified on the user group, then we must always apply this filter.
	global $usersearchfilter;
	$sf=explode(";",$usersearchfilter);
	if (strlen($usersearchfilter)>0)
		{
		for ($n=0;$n<count($sf);$n++)
			{
			$s=explode("=",$sf[$n]);
			if (count($s)!=2) {exit ("Search filter is not correctly configured for this user group.");}

			# Support for "NOT" matching. Return results only where the specified value or values are NOT set.
			$filterfield=$s[0];$filter_not=false;
			if (substr($filterfield,-1)=="!")
				{
				$filter_not=true;
				$filterfield=substr($filterfield,0,-1);# Strip off the exclamation mark.
				}

			# Find field(s) - multiple fields can be returned to support several fields with the same name.
			$f=sql_array("select ref value from resource_type_field where name='" . escape_check($filterfield) . "'");
			if (count($f)==0) {exit ("Field(s) with short name '" . $filterfield . "' not found in user group search filter.");}
			
			# Find keyword(s)
			$ks=explode("|",strtolower(escape_check($s[1])));
			for($x=0;$x<count($ks);$x++){$ks[$x]=cleanse_string($ks[$x],true);} # Cleanse the string as keywords are stored without special characters
			
			$modifiedsearchfilter=hook("modifysearchfilter");
			if ($modifiedsearchfilter){$ks=$modifiedsearchfilter;} 
			$kw=sql_array("select ref value from keyword where keyword in ('" . join("','",$ks) . "')");
			#if (count($k)==0) {exit ("At least one of keyword(s) '" . join("', '",$ks) . "' not found in user group search filter.");}
					
		    if (!$filter_not)
		    	{
		    	# Standard operation ('=' syntax)
			    $sql_join.=" join resource_keyword filter" . $n . " on r.ref=filter" . $n . ".resource and filter" . $n . ".resource_type_field in ('" . join("','",$f) . "') and filter" . $n . ".keyword in ('" . 	join("','",$kw) . "') ";	
			    }
			else
				{
				# Inverted NOT operation ('!=' syntax)
				if ($sql_filter!="") {$sql_filter.=" and ";}
				$sql_filter .= "r.ref not in (select resource from resource_keyword where resource_type_field in ('" . join("','",$f) . "') and keyword in ('" . 	join("','",$kw) . "'))"; # Filter out resources that do contain the keyword(s)
				}
			}
		}
		
	$userownfilter=	hook("userownfilter");
	if ($userownfilter){$sql_join.=$userownfilter;} 
	
	# Handle numeric searches when $config_search_for_number=false, i.e. perform a normal search but include matches for resource ID first
	global $config_search_for_number;
	if (!$config_search_for_number && is_numeric($search))
		{
		# Always show exact resource matches first.
		$order_by="(r.ref='" . $search . "') desc," . $order_by;
		}

                
        # ---------------------------------------------------------------
        # Keyword union assembly.
        # Use UNIONs for keyword matching instead of the older JOIN technique - much faster
        # Assemble the new join from the stored unions
        # ---------------------------------------------------------------
        if (count($sql_keyword_union)>0)
            {
            $sql_join.=",(
                select resource,sum(score) as score,
                " . join(", ",$sql_keyword_union_aggregation) . " from
                (" . join(" union ",$sql_keyword_union) . ") as hits group by resource) as h";
            
            if ($sql_filter!="") {$sql_filter.=" and ";}
            $sql_filter.="r.ref=h.resource and ";
            $sql_filter.=join(" and ",$sql_keyword_union_criteria);
            
            # Use amalgamated resource_keyword hitcounts for scoring (relevance matching based on previous user activity)
            $score="h.score";
            }
                
                
                
                
                
                
                
                
                
                
                
                
                
                
	# --------------------------------------------------------------------------------
	# Special Searches (start with an exclamation mark)
	# --------------------------------------------------------------------------------
	
	# Can only search for resources that belong to themes
	if (checkperm("J"))
		{
		$sql_join.=" join collection_resource jcr on jcr.resource=r.ref join collection jc on jcr.collection=jc.ref and length(jc.theme)>0 ";
		}
		
	# ------ Special searches ------
	# View Last
	if (substr($search,0,5)=="!last") 
		{
		# Replace r2.ref with r.ref for the alternative query used here.
		$order_by=str_replace("r.ref","r2.ref",$order_by);
		if ($orig_order=="relevance") {$order_by="r2.ref desc";}

		# Extract the number of records to produce
		$last=explode(",",$search);
		$last=str_replace("!last","",$last[0]);
		
		if (!is_numeric($last)) {$last=1000;$search="!last1000";} # 'Last' must be a number. SQL injection filter.
		
		# Fix the order by for this query (special case due to inner query)
		$order_by=str_replace("r.rating","rating",$order_by);
				
		return sql_query($sql_prefix . "select distinct *,r2.hit_count score from (select $select from resource r $sql_join where $sql_filter order by ref desc limit $last ) r2 order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# View Resources With No Downloads
	if (substr($search,0,12)=="!nodownloads") 
		{
		if ($orig_order=="relevance") {$order_by="ref desc";}

		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where $sql_filter and ref not in (select distinct object_ref from daily_stat where activity_type='Resource download') order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# Duplicate Resources (based on file_checksum)
	if (substr($search,0,11)=="!duplicates") 
		{
		return sql_query("select distinct r.hit_count score, $select from resource r $sql_join  where $sql_filter and file_checksum in (select file_checksum from (select file_checksum,count(*) dupecount from resource group by file_checksum) r2 where r2.dupecount>1) order by file_checksum",false,$fetchrows);
		}
	
	# View Collection
	if (substr($search,0,11)=="!collection")
		{
		if ($orig_order=="relevance") {$order_by="c.sortorder asc,c.date_added desc,r.ref";}
		$colcustperm=$sql_join;
		$colcustfilter=$sql_filter; // to avoid allowing this sql_filter to be modified by the $access_override search in the smart collection update below!!!
		
		if (getval("k","")!="") {$sql_filter="r.ref>0";} # Special case if a key has been provided.
		
		# Extract the collection number
		$collection=explode(" ",$search);$collection=str_replace("!collection","",$collection[0]);
		$collection=explode(",",$collection);// just get the number
		$collection=escape_check($collection[0]);

		# smart collections update
		global $allow_smart_collections,$smart_collections_async;
		if ($allow_smart_collections){
			global $smartsearch_ref_cache;
			if (isset($smartsearch_ref_cache[$collection])){
				$smartsearch_ref=$smartsearch_ref_cache[$collection]; // this value is pretty much constant
			} else {
				$smartsearch_ref=sql_value("select savedsearch value from collection where ref='$collection'","");
				$smartsearch_ref_cache[$collection]=$smartsearch_ref;
			}
			global $php_path;
			if ($smartsearch_ref!=""){
				if ($smart_collections_async && isset($php_path) && file_exists($php_path . "/php")){
	                exec($php_path . "/php " . dirname(__FILE__)."/../pages/ajax/update_smart_collection.php " . escapeshellarg($collection) . " " . "> /dev/null 2>&1 &");
	            } else {
	                include (dirname(__FILE__)."/../pages/ajax/update_smart_collection.php");
	            }
			}	
		}	
        
		$result=sql_query($sql_prefix . "select distinct c.date_added,c.comment,c.purchase_size,c.purchase_complete,r.hit_count score,length(c.comment) commentset, $select from resource r  join collection_resource c on r.ref=c.resource $colcustperm  where c.collection='" . $collection . "' and $colcustfilter group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		 hook("beforereturnresults","",array($result, $archive)); 
    	
		return $result;
		}
	
	# View Related
	if (substr($search,0,8)=="!related")
		{
		# Extract the resource number
		$resource=explode(" ",$search);$resource=str_replace("!related","",$resource[0]);
		$order_by=str_replace("r.","",$order_by); # UNION below doesn't like table aliases in the order by.
		
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r join resource_related t on (t.related=r.ref and t.resource='" . $resource . "') $sql_join  where 1=1 and $sql_filter group by r.ref 
		UNION
		select distinct r.hit_count score, $select from resource r join resource_related t on (t.resource=r.ref and t.related='" . $resource . "') $sql_join  where 1=1 and $sql_filter group by r.ref 
		order by $order_by" . $sql_suffix,false,$fetchrows);
		}
		
	# Geographic search
	if (substr($search,0,4)=="!geo")
		{
		$geo=explode("t",str_replace(array("m","p"),array("-","."),substr($search,4))); # Specially encoded string to avoid keyword splitting
		$bl=explode("b",$geo[0]);
		$tr=explode("b",$geo[1]);	
		$sql="select r.hit_count score, $select from resource r $sql_join where 

					geo_lat > '" . escape_check($bl[0]) . "'
              and   geo_lat < '" . escape_check($tr[0]) . "'		
              and   geo_long > '" . escape_check($bl[1]) . "'		
              and   geo_long < '" . escape_check($tr[1]) . "'		
                          
		 and $sql_filter group by r.ref order by $order_by";
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
		}


	# Colour search
	if (substr($search,0,7)=="!colour")
		{
		$colour=explode(" ",$search);$colour=str_replace("!colour","",$colour[0]);

		$sql="select r.hit_count score, $select from resource r $sql_join
				where 
					colour_key like '" . escape_check($colour) . "%'
              	or  colour_key like '_" . escape_check($colour) . "%'
                          
		 and $sql_filter group by r.ref order by $order_by";
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
		}		

		
	# Similar to a colour
	if (substr($search,0,4)=="!rgb")
		{
		$rgb=explode(":",$search);$rgb=explode(",",$rgb[1]);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 and $sql_filter group by r.ref order by (abs(image_red-" . $rgb[0] . ")+abs(image_green-" . $rgb[1] . ")+abs(image_blue-" . $rgb[2] . ")) asc limit 500" . $sql_suffix,false,$fetchrows);
		}
		
	# Has no preview image
	if (substr($search,0,10)=="!nopreview")
		{
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=0 and $sql_filter group by r.ref" . $sql_suffix,false,$fetchrows);
		}		
		
	# Similar to a colour by key
	if (substr($search,0,10)=="!colourkey")
		{
		# Extract the colour key
		$colourkey=explode(" ",$search);$colourkey=str_replace("!colourkey","",$colourkey[0]);
		
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 and left(colour_key,4)='" . $colourkey . "' and $sql_filter group by r.ref" . $sql_suffix,false,$fetchrows);
		}
	
	global $config_search_for_number;
	if (($config_search_for_number && is_numeric($search)) || substr($search,0,9)=="!resource")
        {
		$theref = escape_check($search);
		$theref = preg_replace("/[^0-9]/","",$theref);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where r.ref='$theref' and $sql_filter group by r.ref" . $sql_suffix);
        }

	# Searching for pending archive
	if (substr($search,0,15)=="!archivepending")
		{
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where archive=1 and ref>0 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	if (substr($search,0,12)=="!userpending")
		{
		if ($orig_order=="rating") {$order_by="request_count desc," . $order_by;}
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where archive=-1 and ref>0 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
		
	# View Contributions
	if (substr($search,0,14)=="!contributions") 
		{
		global $userref;
		
		# Extract the user ref
		$cuser=explode(" ",$search);$cuser=str_replace("!contributions","",$cuser[0]);
		
		if ($userref==$cuser) {$sql_filter="archive='$archive'";$sql_join="";} # Disable permissions when viewing your own contributions - only restriction is the archive status
		$select=str_replace(",rca.access group_access,rca2.access user_access ",",null group_access, null user_access ",$select);
		return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where created_by='" . $cuser . "' and r.ref > 0 and $sql_filter group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);
		}
	
	# Search for resources with images
	if ($search=="!images") return sql_query($sql_prefix . "select distinct r.hit_count score, $select from resource r $sql_join  where has_image=1 group by r.ref order by $order_by" . $sql_suffix,false,$fetchrows);

	# Search for resources not used in Collections
	if (substr($search,0,7)=="!unused") 
		{
		return sql_query($sql_prefix . "SELECT distinct $select FROM resource r $sql_join  where r.ref>0 and r.ref not in (select c.resource from collection_resource c) and $sql_filter" . $sql_suffix,false,$fetchrows);
		}	
	
	# Search for resources with an empty field, ex: !empty18  or  !emptycaption
	if (substr($search,0,6)=="!empty"){
		$nodatafield=explode(" ",$search);$nodatafield=rtrim(str_replace("!empty","",$nodatafield[0]),',');
		
		if (!is_numeric($nodatafield)){$nodatafield=sql_value("select ref value from resource_type_field where name='".escape_check($nodatafield)."'","");}
		if ($nodatafield=="" ||!is_numeric($nodatafield)){exit('invalid !empty search');}
		$rtype=sql_value("select resource_type value from resource_type_field where ref='$nodatafield'",0);
		
		if ($rtype!=0){
			if ($rtype==999){
				$restypesql="(r.archive=1 or r.archive=2) and ";$sql_filter=str_replace("archive='0'","(archive=1 or archive=2)",$sql_filter);
			} else {
				$restypesql="r.resource_type ='$rtype' and ";
			} 
		} else {
				$restypesql="";
			}

		return sql_query("$sql_prefix select distinct r.hit_count score,$select from resource r left outer join resource_data rd on r.ref=rd.resource and rd.resource_type_field='$nodatafield' $sql_join where $restypesql (rd.value ='' or rd.value is null or rd.value=',')  and $sql_filter group by r.ref order by $order_by $sql_suffix");
		}
	
	# Search for a list of resources
	# !listall = archive state is not applied as a filter to the list of resources.
	if (substr($search,0,5)=="!list") 
		{	
		$resources=explode(" ",$search);
		if (substr($search,0,8)=="!listall"){
			$resources=str_replace("!listall","",$resources[0]);
		} else {
			$resources=str_replace("!list","",$resources[0]);
		}
		$resources=explode(",",$resources);// separate out any additional keywords
		$resources=escape_check($resources[0]);
		if (strlen(trim($resources))==0){
			$resources="where r.ref IS NULL";
		}
		else {	
		$resources="where (r.ref='".str_replace(":","' OR r.ref='",$resources) . "')";
		}
	
		return sql_query($sql_prefix . "SELECT distinct r.hit_count score, $select FROM resource r $sql_join $resources and $sql_filter order by $order_by" . $sql_suffix,false,$fetchrows);
		}		

	# Within this hook implementation, set the value of the global $sql variable:
	# Since there will only be one special search executed at a time, only one of the
	# hook implementations will set the value.  So, you know that the value set
	# will always be the correct one (unless two plugins use the same !<type> value).
	$sql="";
	hook("addspecialsearch", "", array($search));
	
	if($sql != "")
	{
		debug("Addspecialsearch hook returned useful results.");
		return sql_query($sql_prefix . $sql . $sql_suffix,false,$fetchrows);
	}

	# -------------------------------------------------------------------------------------
	# Standard Searches
	# -------------------------------------------------------------------------------------
	
	# We've reached this far without returning.
	# This must be a standard (non-special) search.
	
	# Construct and perform the standard search query.
	#$sql="";
	if ($sql_filter!="")
		{
		if ($sql!="") {$sql.=" and ";}
		$sql.=$sql_filter;
		}

	# Append custom permissions	
	$t.=$sql_join;
	
	if ($score=="") {$score="r.hit_count";} # In case score hasn't been set (i.e. empty search)
	global $max_results;
	if (($t2!="") && ($sql!="")) {$sql=" and " . $sql;}
	
	# Compile final SQL

	# Performance enhancement - set return limit to number of rows required
	if ($search_sql_double_pass_mode && $fetchrows!=-1) {$max_results=$fetchrows;}

	$results_sql=$sql_prefix . "select distinct $score score, $select from resource r" . $t . "  where $t2 $sql group by r.ref order by $order_by limit $max_results" . $sql_suffix;

	# Debug
	debug("altert " . $results_sql);

#echo $results_sql;

	# Execute query
	$result=sql_query($results_sql,false,$fetchrows);

	# Performance improvement - perform a second count-only query and pad the result array as necessary
	if ($search_sql_double_pass_mode && count($result)>=$max_results)
		{
		$count_sql="select count(distinct r.ref) value from resource r" . $t . "  where $t2 $sql";		
		$count=sql_value($count_sql,0);
		$result=array_pad($result,$count,0);
		}

	debug("Search found " . count($result) . " results");
	if (count($result)>0) 
	    {
        hook("beforereturnresults","",array($result, $archive));   
	    return $result;
	    }
	
	# (temp) - no suggestion for field-specific searching for now - TO DO: modify function below to support this
	if (strpos($search,":")!==false) {return "";}
	
	# All keywords resolved OK, but there were no matches
	# Remove keywords, least used first, until we get results.
	$lsql="";
	$omitmatch=false;
	for ($n=0;$n<count($keywords);$n++)
		{
		if (substr($keywords[$n],0,1)=="-")
			{
			$omitmatch=true;
			$omit=$keywords[$n];
			}
		if ($lsql!="") {$lsql.=" or ";}
		$lsql.="keyword='" . escape_check($keywords[$n]) . "'";
		}
	if ($omitmatch)
		{
		return trim_spaces(str_replace(" " . $omit . " "," "," " . join(" ",$keywords) . " "));		
		}
	if ($lsql!="")
		{
		$least=sql_value("select keyword value from keyword where $lsql order by hit_count asc limit 1","");
		return trim_spaces(str_replace(" " . $least . " "," "," " . join(" ",$keywords) . " "));
		}
	else
		{
		return array();
		}
	}
}
