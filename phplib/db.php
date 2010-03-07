<?

function db_init() {
  $db = NewADOConnection(pref_getServerPreference('database'));
  if (!$db) {
    die("Connection failed");
  }
  ADOdb_Active_Record::SetDatabaseAdapter($db);
  $db->Execute('set names utf8');
  $GLOBALS['db'] = $db;
  // $db->debug = true;
}

function db_execute($query) {
  return $GLOBALS['db']->execute($query);
}

function db_changeDatabase($dbName) {
  $dbName = addslashes($dbName);
  return logged_query("use $dbName");
}

/**
 * Returns an array mapping user, password, host and database to their respective values.
 **/
function db_splitDsn() {
  $result = array();
  $dsn = pref_getServerPreference('database');
  $prefix = 'mysql://';
  assert(text_startsWith($dsn, $prefix));
  $dsn = substr($dsn, strlen($prefix));

  $parts = split("[:@/]", $dsn);
  assert(count($parts) == 3 || count($parts) == 4);

  if (count($parts) == 4) {
    $result['user'] = $parts[0];
    $result['password'] = $parts[1];
    $result['host'] = $parts[2];
    $result['database'] = $parts[3];
  } else {
    $result['user'] = $parts[0];
    $result['host'] = $parts[1];
    $result['database'] = $parts[2];
    $result['password'] = '';
  }
  return $result;
}

/**
 * For queries that count rows, or that otherwise return a single record with
 * an integer, return that integer.
 */
function db_fetchInteger($result) {
  $row = mysql_fetch_row($result);
  mysql_free_result($result);
  return (int)$row[0];
}

function db_fetchSingleRow($result) {
  if ($result) {
    $row = mysql_fetch_assoc($result);
    mysql_free_result($result);
    return $row;
  } else {
    return NULL;
  }
}

function db_getLastInsertedId() {
  $query = "select last_insert_id()";
  return db_fetchInteger(logged_query($query));
}

function db_getArray($dbSet) {
  $result = array();
  while ($dbSet && $row = mysql_fetch_assoc($dbSet)) {
    $result[] = $row;
  }
  mysql_free_result($dbSet);
  return $result;
}

function db_getScalarArray($recordSet) {
  $result = array();
  while (!$recordSet->EOF) {
    $result[] = $recordSet->fields[0];
    $recordSet->MoveNext();
  }
  return $result;
}

function db_getSingleValue($recordSet) {
  return $recordSet->fields[0];
}

function db_getCompactIntArray($dbSet) {
  $result = int_create(mysql_num_rows($dbSet));
  $pos = 0;
  while ($dbSet && $row = mysql_fetch_row($dbSet)) {
    int_put($result, $pos++, $row[0]);
  }
  mysql_free_result($dbSet);
  return $result;
}

function logged_query($query) {
  debug_resetClock();
  $result = mysql_query($query);
  debug_stopClock($query);
  if (!$result) {
    $errno = mysql_errno();
    $message = "A intervenit o eroare $errno la comunicarea cu baza de date: ";

    if ($errno == 1139) {
      $message .= "Verificați că parantezele folosite sunt închise corect.";
    } else if ($errno == 1049) {
      $message .= "Nu există o bază de date pentru această versiune LOC.";
    } else {
      $message .= mysql_error();
    }

    $query = htmlspecialchars($query);
    $message .= "<br/>Query MySQL: [$query]<br/>";

    if (smarty_isInitialized()) {
      smarty_assign('errorMessage', $message);
      smarty_displayCommonPageWithSkin('errorMessage.ihtml');
    } else {
      var_dump($message);
    }
    exit;  
  }
  return $result;
}

function db_tableExists($tableName) {
  return db_fetchSingleRow(logged_query("show tables like '$tableName'")) !== false;
}

function db_executeSqlFile($fileName) {
  $statements = file_get_contents($fileName);
  $statements = explode(';', $statements);
  foreach ($statements as $statement) {
    if (trim($statement) != '') {
      logged_query($statement);
    }
  }
}

function db_getDefinitionById($id) {
  $query = "select * from Definition where Id = '$id'";
  return db_fetchSingleRow(logged_query($query));
}

function db_getDefinitionsByIds($ids) {
  $query = "select * from Definition where Id in ($ids) order by Lexicon";
  return logged_query($query);
}

function db_getDefinitionsByLexemId($lexemId) {
  $query = "select Definition.* from Definition, LexemDefinitionMap " .
    "where Definition.Id = LexemDefinitionMap.DefinitionId " .
    "and LexemDefinitionMap.LexemId = $lexemId " .
    "and Status in (0, 1) " .
    "order by SourceId";
  return logged_query($query);
}

function db_selectDefinitionsHavingTypos() {
  return logged_query("select distinct Definition.* from Definition, Typo " .
                      "where Definition.Id = Typo.DefinitionId " .
                      "order by Lexicon limit 500");
}

function db_selectDefinitionsForLexemIds($lexemIds, $sourceId, $preferredWord, $exclude_unofficial) {
  $sourceClause = $sourceId ? "and D.SourceId = $sourceId" : '';
  $excludeClause = $exclude_unofficial ? "and S.IsOfficial <> 0 " : '';
  $query = "select distinct D.* " .
    "from Definition D, LexemDefinitionMap L, Source S " .
    "where D.Id = L.DefinitionId " .
    "and L.LexemId in ($lexemIds) " .
	"and D.SourceId = S.Id " .
    "and D.Status = 0 " .
	$excludeClause . 
    $sourceClause .
    " order by (D.Lexicon = '$preferredWord') desc, " .
    "S.IsOfficial desc, D.Lexicon, S.DisplayOrder";
  return logged_query($query);
}

function db_countDefinitions() {
  return db_fetchInteger(logged_query("select count(*) from Definition"));
}

function db_countAssociatedDefinitions() {
  // same as select count(distinct DefinitionId) from LexemDefinitionMap,
  // only faster.
  $query = 'select count(*) from ' .
    '(select count(*) from LexemDefinitionMap group by DefinitionId) ' .
    'as someLabel';
  return db_fetchInteger(logged_query($query));
}

function db_countDefinitionsByStatus($status) {
  $query = "select count(*) from Definition where Status = $status";
  return db_fetchInteger(logged_query($query));
}

function db_countRecentDefinitions($minCreateDate) {
  $query = "select count(*) from Definition where " .
    "CreateDate >= $minCreateDate and " .
    "Status = " . ST_ACTIVE;
  return db_fetchInteger(logged_query($query));
}

function db_countDefinitionsHavingTypos() {
  $query = 'select count(distinct DefinitionId) from Typo';
  return db_fetchInteger(logged_query($query));
}

function db_getUnassociatedDefinitions() {
  $query = 'select * from Definition ' .
    'where Status != 2 ' .
    'and Id not in (select DefinitionId from LexemDefinitionMap)';
  return logged_query($query);
}

function db_searchRegexp($regexp, $hasDiacritics, $sourceId) {
  $field = $hasDiacritics ? 'lexem_neaccentuat' : 'lexem_utf8_general';
  $sourceClause = $sourceId ? "and Definition.SourceId = $sourceId " : '';
  $sourceJoin = $sourceId ?  "join LexemDefinitionMap " .
    "on lexem_id = LexemDefinitionMap.LexemId " .
    "join Definition on LexemDefinitionMap.DefinitionId = Definition.Id " : '';
  $query = "select * from lexems " .
    $sourceJoin .
    "where $field $regexp " .
    $sourceClause .
    "order by lexem_neaccentuat limit 1000";
  return logged_query($query);
}

function db_countRegexpMatches($regexp, $hasDiacritics, $sourceId) {
  $field = $hasDiacritics ? 'lexem_neaccentuat' : 'lexem_utf8_general';
  $sourceClause = $sourceId ? "and Definition.SourceId = $sourceId " : '';
  $sourceJoin = $sourceId ?  "join LexemDefinitionMap " .
    "on lexem_id = LexemDefinitionMap.LexemId " .
    "join Definition on LexemDefinitionMap.DefinitionId = Definition.Id " : '';
  $query = "select count(*) from lexems " .
    $sourceJoin .
    "where $field $regexp " .
    $sourceClause;
  return db_fetchInteger(logged_query($query));
}

function db_searchLexems($cuv, $hasDiacritics) {
  $field = $hasDiacritics ? 'lexem_neaccentuat' : 'lexem_utf8_general';
  $query = "select * from lexems " .
    "where $field = '$cuv' " .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_searchWordlists($cuv, $hasDiacritics) {
  $field = $hasDiacritics ? 'wl_neaccentuat' : 'wl_utf8_general';
  $query = "select distinct lexems.* from wordlist, lexems " .
    "where wl_lexem = lexem_id and $field = '$cuv' " .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_getLocWordlists($cuv, $hasDiacritics) {
  $field = $hasDiacritics ? 'wl_neaccentuat' : 'wl_utf8_general';
  $query = "select distinct wordlist.* from wordlist, lexems " .
    "where wl_lexem = lexem_id and $field = '$cuv' " .
    "and lexem_is_loc order by lexem_neaccentuat";
  return logged_query($query);
}

function db_searchApproximate($cuv, $hasDiacritics) {
  $field = $hasDiacritics ? 'lexem_neaccentuat' : 'lexem_utf8_general';
  $query = "select * from lexems " .
    "where dist2($field, '$cuv') " .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_searchModerator($regexp, $hasDiacritics, $sourceId, $status,
                            $userId, $minCreateDate, $maxCreateDate) {
  $field = $hasDiacritics ? 'lexem_neaccentuat' : 'lexem_utf8_general';
  $sourceClause = $sourceId ? "and Definition.SourceId = $sourceId" : '';
  $userClause = $userId ? "and Definition.UserId = $userId" : '';

  $query = "select distinct Definition.* " .
    "from lexems " .
    "join LexemDefinitionMap " .
    "on lexem_id = LexemDefinitionMap.LexemId " .
    "join Definition on LexemDefinitionMap.DefinitionId = Definition.Id " .
    "where $field $regexp " .
    "and Definition.Status = $status " .
    "and Definition.CreateDate >= $minCreateDate " .
    "and Definition.CreateDate <= $maxCreateDate " .
    $sourceClause . " " . $userClause . " " .
    "order by Definition.Lexicon, Definition.SourceId " .
    "limit 500";
  return logged_query($query);
}

function db_searchDeleted($regexp, $hasDiacritics, $sourceId, $userId,
                          $minCreateDate, $maxCreateDate) {
  $collate = $hasDiacritics ? '' : 'collate utf8_general_ci';
  $sourceClause = $sourceId ? "and Definition.SourceId = $sourceId" : '';
  $userClause = $userId ? "and Definition.UserId = $userId" : '';

  $query = "select * from Definition " .
    "where Lexicon $collate $regexp " .
    "and Status = " . ST_DELETED . " " .
    "and CreateDate >= $minCreateDate " .
    "and CreateDate <= $maxCreateDate " .
    $sourceClause . " " . $userClause . " " .
    "order by Lexicon, SourceId " .
    "limit 500";
  return logged_query($query);
}

function db_searchDefId($defId) {
  $query = "select * from Definition where Id = '$defId' and Status = 0 ";
  return db_fetchSingleRow(logged_query($query));
}

function db_searchLexemId($lexemId, $exclude_unofficial) {
  $lexemId = addslashes($lexemId);
  $excludeClause = $exclude_unofficial ? "and S.IsOfficial <> 0 " : '';
  $query = "select D.* from Definition D, LexemDefinitionMap L, Source S " .
    "where D.Id = L.DefinitionId " .
    "and D.SourceId = S.Id " .
    "and L.LexemId = '$lexemId' " .
	$excludeClause . 
    "and D.Status = 0 " .
    "order by S.IsOfficial desc, S.DisplayOrder, D.Lexicon";
  return logged_query($query);
}

function db_selectTop() {
  return logged_query("select Nick, count(*) as NumDefinitions, " .
                      "sum(length(InternalRep)) as NumChars, " .
                      "max(CreateDate) as Timestamp " .
                      "from Definition, User " .
                      "where Definition.UserId = User.Id " .
                      "and Definition.Status = 0 " .
                      "group by Nick");
}

function db_getDefinitionsByMinModDate($modDate) {
  $query = "select * from Definition " .
    "where Status = " . ST_ACTIVE . " and " .
    "ModDate >= '$modDate' " .
    "order by ModDate, Id";
  return logged_query($query);
}

function db_getLexemsByMinModDate($modDate) {
  $query = "select Definition.Id, lexem_neaccentuat " .
    "from Definition force index(ModDate), LexemDefinitionMap, lexems " .
    "where Definition.Id = LexemDefinitionMap.DefinitionId " .
    "and LexemDefinitionMap.LexemId = lexem_id " .
    "and Definition.Status = 0 " .
    "and Definition.ModDate >= $modDate " .
    "order by Definition.ModDate, Definition.Id";
  return logged_query($query);
}

function db_getUpdate3Definitions($modDate) {
  // Do not report deleted / pending definitions the first time this script is invoked
  $statusClause = $modDate ? "" : " and Status = 0";
  $query = "select * from Definition " .
    "where ModDate >= '$modDate' $statusClause " .
    "order by ModDate, Id";
  return logged_query($query);
}

function db_getUpdate3LexemIds($modDate) {
  // Do not report deleted / pending definitions the first time this script is invoked
  $statusClause = $modDate ? "" : " and Status = 0";
  $query = "select Definition.Id, LexemId " .
    "from Definition force index(ModDate), LexemDefinitionMap " .
    "where Definition.Id = LexemDefinitionMap.DefinitionId " .
    "and Definition.ModDate >= $modDate $statusClause " .
    "order by Definition.ModDate, Definition.Id";
  return logged_query($query);
}

function db_getUpdate3Lexems($modDate) {
  $query = "select * from lexems " .
    "where ModDate >= '$modDate' " .
    "order by ModDate, lexem_id";
  return logged_query($query);
}

function db_getTypoById($id) {
  $query = "select * from Typo where Id = '$id'";
  return db_fetchSingleRow(logged_query($query));
}

function db_insertTypo($typo) {
  $query = sprintf("insert into Typo set " .
                   "DefinitionId = '%d', " .
                   "Problem = '%s'",
                   $typo->definitionId,
                   addslashes($typo->problem));
  return logged_query($query);
}

function db_getTyposByDefinitionId($definitionId) {
  return logged_query("select * from Typo where DefinitionId = '$definitionId'");
}

function db_deleteTyposByDefinitionId($definitionId) {
  return logged_query("delete from Typo where DefinitionId = '$definitionId'");
}

function db_deleteTypo($typo) {
  logged_query("delete from Typo where Id = {$typo->id}");
}

function db_insertDefinition($definition) {
  $query = sprintf("insert into Definition set UserId = '%d', " .
                   "SourceId = '%d', " .
                   "Displayed = '%d', " .
                   "Lexicon = '%s', " .
                   "InternalRep = '%s', " .
                   "HtmlRep = '%s', " .
                   "Status = '%d', " .
                   "CreateDate = '%d', " .
                   "ModDate = '%d'",
                   $definition->userId,
                   $definition->sourceId,
                   $definition->displayed,
                   addslashes($definition->lexicon),
                   addslashes($definition->internalRep),
                   addslashes($definition->htmlRep),
                   $definition->status,
                   $definition->createDate,
                   $definition->modDate);
  return logged_query($query);
}

function db_updateDefinition($definition) {
  $query = sprintf("update Definition set UserId = '%d', " .
                   "SourceId = '%d', " .
                   "Displayed = '%d', " .
                   "Lexicon = '%s', " .
                   "InternalRep = '%s', " .
                   "HtmlRep = '%s', " .
                   "Status = '%d', " .
                   "CreateDate = '%d', " .
                   "ModDate = '%d', " .
                   "ModUserId = '%d' " .
                   "where Id = '%d'",
                   $definition->userId,
                   $definition->sourceId,
                   $definition->displayed,
                   addslashes($definition->lexicon),
                   addslashes($definition->internalRep),
                   addslashes($definition->htmlRep),
                   $definition->status,
                   $definition->createDate,
                   $definition->modDate,
                   session_getUserId(),
                   $definition->id);
  return logged_query($query);
}

function db_updateDefinitionModDate($defId, $modDate) {
  $query = sprintf("update Definition set ModDate = '$modDate' " .
                   "where Id = '$defId'");
  return logged_query($query);
}

function db_updateLexemModDate($lexemId, $modDate) {
  $query = sprintf("update lexems set ModDate = '$modDate' " .
                   "where lexem_id = '$lexemId'");
  return logged_query($query);
}

function db_getLexemHomonyms($lexem) {
  $unaccented = addslashes($lexem->unaccented);
  $query = "select * from lexems " .
    "where lexem_neaccentuat = '" . $unaccented . "' " .
    "and lexem_id != " . $lexem->id;
  return logged_query($query);
}

function db_getModelTypeById($id) {
  $query = "select * from model_types where mt_id = '$id'";
  return db_fetchSingleRow(logged_query($query));
}

function db_getModelTypeByValue($value) {
  $value = addslashes($value);
  $query = "select * from model_types where mt_value = '$value'";
  return db_fetchSingleRow(logged_query($query));
}

function db_selectAllModelTypes() {
  $query = 'select * from model_types order by mt_value';
  return logged_query($query);
}

function db_selectAllCanonicalModelTypes() {
  $query = 'select * from model_types where mt_value = mt_canonical ' .
    'and mt_value != "T" ' .
    'order by mt_value';
  return logged_query($query);
}

function db_countModelsByModelType($mt) {
  $query = "select count(*) from models where model_type = '" . $mt->value
    . "'";
  return db_fetchInteger(logged_query($query));
}

function db_insertModelType($mt) {
  $query = sprintf("insert into model_types set " .
                   "mt_value = '%s', " .
                   "mt_descr = '%s'",
                   addslashes($mt->value),
                   addslashes($mt->description));
  return logged_query($query);
}

function db_updateModelType($mt) {
  $query = sprintf("update model_types set " .
                   "mt_value = '%s', " .
                   "mt_descr = '%s' " .
                   "where mt_id = '%d'",
                   addslashes($mt->value),
                   addslashes($mt->description),
                   $mt->id);
  return logged_query($query);
}

function db_insertModel($m) {
  $query = sprintf("insert into models set " .
                   "model_type = '%s', " .
                   "model_no = '%s', " .
                   "model_descr = '%s', " .
                   "model_exponent = '%s', " .
                   "model_flag = '%d'",
                   addslashes($m->modelType),
                   addslashes($m->number),
                   addslashes($m->description),
                   addslashes($m->exponent),
                   $m->flag
                   );
  return logged_query($query);
}

function db_updateModel($m) {
  $query = sprintf("update models set " .
                   "model_type = '%s', " .
                   "model_no = '%s', " .
                   "model_descr = '%s', " .
                   "model_exponent = '%s', " .
                   "model_flag = '%d' " .
                   "where model_id = '%d'",
                   addslashes($m->modelType),
                   addslashes($m->number),
                   addslashes($m->description),
                   addslashes($m->exponent),
                   $m->flag,
                   $m->id);
  return logged_query($query);
}

function db_deleteModel($model) {
  return logged_query("delete from models where model_id = {$model->id}");
}

function db_getModelDescriptionsByModelId($modelId) {
  $modelId = addslashes($modelId);
  $query = "select * from model_description " .
    "where md_model = '$modelId' " .
    "order by md_infl, md_variant, md_order ";
  return logged_query($query);
}

function db_getModelDescriptionsByModelIdInflId($modelId, $inflId) {
  $modelId = addslashes($modelId);
  $inflId = addslashes($inflId);
  $query = "select * from model_description " .
    "where md_model = '$modelId' " .
    "and md_infl = '$inflId' " .
    "order by md_variant, md_order ";
  return logged_query($query);
}

function db_insertModelDescription($md) {
  $query = sprintf("insert into model_description set " .
                   "md_model = '%d', " .
                   "md_infl = '%d', " .
                   "md_variant = '%d', " .
                   "md_order = '%d', " .
                   "md_transf = '%d', " .
                   "md_accent_shift = '%d', " .
                   "md_vowel = '%s'",
                   $md->modelId,
                   $md->inflectionId,
                   $md->variant,
                   $md->order,
                   $md->transformId,
                   $md->accentShift,
                   addslashes($md->accentedVowel));
  return logged_query($query);
}

function db_updateModelDescription($md) {
  $query = sprintf("update model_description set " .
                   "md_model = '%d', " .
                   "md_infl = '%d', " .
                   "md_variant = '%d', " .
                   "md_order = '%d', " .
                   "md_transf = '%d', " .
                   "md_accent_shift = '%d', " .
                   "md_vowel = '%s' " .
                   "where md_id = '%d'",
                   $md->modelId,
                   $md->inflectionId,
                   $md->variant,
                   $md->order,
                   $md->transformId,
                   $md->accentShift,
                   addslashes($$md->accentedVowel),
                   $md->id);
  return logged_query($query);
}

function db_deleteModelDescriptionsByModelInflection($modelId, $inflectionId) {
  $query = "delete from model_description where md_model = $modelId " .
    "and md_infl = $inflectionId";
  return logged_query($query);
}

function db_deleteModelDescriptionsByModel($modelId) {
  $query = "delete from model_description where md_model = $modelId";
  return logged_query($query);
}

function db_deleteModelType($modelType) {
  $query = "delete from model_types where mt_id = " . $modelType->id;
  logged_query($query);
}

function db_getModelByTypeNumber($type, $number) {
  $type = addslashes($type);
  $number = addslashes($number);
  $query = "select * from models where model_type = '$type' " .
    "and model_no = '$number'";
  return db_fetchSingleRow(logged_query($query));
}

function db_getModelsByType($type) {
  $type = addslashes($type);
  $query = "select * from models where model_type = '$type' " .
    "order by cast(model_no as unsigned)";
  return logged_query($query);
}

function db_getModelById($id) {
  $query = "select * from models where model_id = $id";
  return db_fetchSingleRow(logged_query($query));
}

function db_selectAllModels() {
  $query = 'select * from models order by model_type, model_no';
  return logged_query($query);
}

function db_getLexemById($id) {
  $query = "select * from lexems where lexem_id = '$id'";
  return db_fetchSingleRow(logged_query($query));
}

function db_getLexemsByDefinitionId($definitionId) {
  $query = "select lexems.* from lexems, LexemDefinitionMap " .
    "where lexems.lexem_id = LexemDefinitionMap.LexemId " .
    "and LexemDefinitionMap.DefinitionId = '$definitionId'";
  return logged_query($query);
}

function db_getLexemsByUnaccented($unaccented) {
  $unaccented = addslashes($unaccented);
  $query = "select * from lexems where lexem_neaccentuat = '$unaccented'";
  return logged_query($query);
}

function db_getLexemsByForm($form) {
  $form = addslashes($form);
  $query = "select * from lexems where lexem_forma = '$form'";
  return logged_query($query);
}

function db_getLexemsByPartialUnaccented($name) {
  $name = addslashes($name);
  $query = "select * from lexems where lexem_neaccentuat like '$name%' " .
    "order by lexem_neaccentuat limit 10";
  return logged_query($query);  
}

function db_getLexemsByUnaccentedPartialDescription($name, $description) {
  $name = addslashes($name);
  $description = addslashes($description);
  $query = "select * from lexems where lexem_neaccentuat = '$name' " .
    "and lexem_descr like '$description%' " .
    "order by lexem_neaccentuat, lexem_descr limit 10";
  return logged_query($query);  
}

function db_getLexemsByUnaccentedDescription($unaccented, $description) {
  $unaccented = addslashes($unaccented);
  $description = addslashes($description);
  $query = "select * from lexems where lexem_neaccentuat = '$unaccented' " .
    "and lexem_descr = '$description'";
  return logged_query($query);  
}

function db_getLexemsByReverseSuffix($suffix, $excludeLexemId, $limit) {
  $query = "select * from lexems where lexem_invers like '$suffix%' " .
    "and lexem_model_type != 'T' " .
    "and lexem_id != $excludeLexemId " .
    "group by lexem_model_type, lexem_model_no " .
    "limit $limit";
  return logged_query($query);
}

function db_getLexemsByModel($modelType, $modelNumber) {
  $modelType = addslashes($modelType);
  $modelNumber = addslashes($modelNumber);
  $query = "select * from lexems where lexem_model_type = '$modelType' " .
    "and lexem_model_no = '$modelNumber' " .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_getLexemByCanonicalModelSuffix($modelType, $modelNumber, $suffix) {
  $modelType = addslashes($modelType);
  $modelNumber = addslashes($modelNumber);
  $query = "select lexems.* from lexems, model_types " .
    "where lexem_model_type = mt_value " .
    "and mt_canonical = '$modelType' " .
    "and lexem_model_no = '$modelNumber' " .
    "and lexem_invers like '$suffix%' " .
    "order by lexem_forma desc limit 1";
  return db_fetchSingleRow(logged_query($query));
}

function db_getLexemByUnaccentedCanonicalModel($unaccented, $modelType,
                                               $modelNumber) {
  $unaccented = addslashes($unaccented);
  $modelType = addslashes($modelType);
  $modelNumber = addslashes($modelNumber);
  $query = "select lexems.* from lexems, model_types " .
    "where lexem_model_type = model_types.mt_value " .
    "and model_types.mt_canonical = '$modelType' " .
    "and lexem_model_no = '$modelNumber' ".
    "and lexem_neaccentuat = '$unaccented' " .
    "limit 1";
  return db_fetchSingleRow(logged_query($query));
}

function db_getLexemsByCanonicalModel($modelType, $modelNumber) {
  $modelType = addslashes($modelType);
  $modelNumber = addslashes($modelNumber);
  $query = "select lexems.* from lexems, model_types " .
    "where lexem_model_type = model_types.mt_value " .
    "and model_types.mt_canonical = '$modelType' " .
    "and lexem_model_no = '$modelNumber'" .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_countLexems() {
  $query = "select count(*) from lexems";
  return db_fetchInteger(logged_query($query));
}

function db_countAssociatedLexems() {
  // same as select count(distinct LexemId) from LexemDefinitionMap,
  // only faster.
  $query = 'select count(*) from (select count(*) from LexemDefinitionMap ' .
    'group by LexemId) as someLabel';
  return db_fetchInteger(logged_query($query));
}

function db_getUnassociatedLexems() {
  $query = 'select * from lexems ' .
    'where lexem_id not in (select LexemId from LexemDefinitionMap) ' .
    'order by lexem_neaccentuat';
  return logged_query($query);
}

function db_selectAllLexems() {
  $query = 'select * from lexems';
  return logged_query($query);
}

function db_countTemporaryLexems() {
  $query = 'select count(*) from lexems where lexem_model_type = "T"';
  return db_fetchInteger(logged_query($query));
}

function db_getTemporaryLexems() {
  $query = 'select * from lexems where lexem_model_type = "T" order by lexem_neaccentuat';
  return logged_query($query);
}

function db_getTemporaryLexemsFromSource($sourceId) {
  $query = "select distinct lexems.* from lexems, LexemDefinitionMap, Definition " .
    "where lexems.lexem_id = LexemDefinitionMap.LexemId and LexemDefinitionMap.DefinitionId = Definition.Id " .
    "and Definition.Status = 0 and Definition.SourceId = $sourceId and lexem_model_type = 'T' " .
    "order by lexem_neaccentuat";
  return logged_query($query);
}

function db_countLexemsWithComments() {
  $query = 'select count(*) from lexems where lexem_comment != ""';
  return db_fetchInteger(logged_query($query));
}

function db_getLexemsWithComments() {
  $query = 'select * from lexems where lexem_comment != "" ' .
    'order by lexem_neaccentuat';
  return logged_query($query);
}

function db_countLexemsWithoutAccents() {
  $query = 'select count(*) from lexems where lexem_forma not rlike "\'" ' .
    'and not lexem_no_accent';
  return db_fetchInteger(logged_query($query));
}

function db_getLexemsWithoutAccents() {
  $query = 'select * from lexems where lexem_forma not rlike "\'" ' .
    'and not lexem_no_accent limit 1000';
  return logged_query($query);
}

function db_getRandomLexemsWithoutAccents($count) {
  $query = 'select * from lexems where lexem_forma not rlike "\'" ' .
    'and not lexem_no_accent order by rand() limit ' . $count;
  return logged_query($query);
}

function db_countAmbiguousLexems() {
  $query = "select count(*) from (select lexems.*, count(*) as c from lexems where lexem_descr = '' group by lexem_forma having c > 1) as t1";
  return db_fetchInteger(logged_query($query));
}

function db_getAmbiguousLexems() {
  $query = "select lexems.*, count(*) as c from lexems where lexem_descr = '' group by lexem_forma having c > 1";
  return logged_query($query);
}

function db_getParticiplesForVerbModel($modelNumber, $participleNumber, $partInflId) {
  $query = "select part.* from lexems part, wordlist, lexems infin " .
    "where infin.lexem_model_type = 'VT' " .
    "and infin.lexem_model_no = '$modelNumber' " .
    "and wl_lexem = infin.lexem_id " .
    "and wl_analyse = $partInflId " .
    "and part.lexem_neaccentuat = wl_neaccentuat " .
    "and part.lexem_model_type = 'A' " .
    "and part.lexem_model_no = '$participleNumber' " .
    "order by part.lexem_neaccentuat";
  return logged_query($query);
}

function db_getLexemsForScrabbleDownload() {
  $query = 'select * from lexems where lexem_is_loc ' .
    'order by lexem_neaccentuat';
  return logged_query($query);
}

function db_insertLexem($lexem) {
  $query = sprintf("insert into lexems set " .
                   "lexem_forma = '%s', " .
                   "lexem_neaccentuat = '%s', " .
                   "lexem_utf8_general = '%s', " .
                   "lexem_invers = '%s', " .
                   "lexem_descr = '%s', " .
                   "lexem_model_type = '%s', " .
                   "lexem_model_no = '%s', " .
                   "lexem_restriction = '%s', " .
                   "lexem_parse_info = '%s', " .
                   "lexem_comment = '%s', " .
                   "lexem_is_loc = '%d', " .
                   "lexem_no_accent = '%d', " .
                   "CreateDate = '%d', " .
                   "ModDate = '%d'",
                   addslashes($lexem->form),
                   addslashes($lexem->unaccented),
                   addslashes($lexem->unaccented),
                   addslashes($lexem->reverse),
                   addslashes($lexem->description),
                   addslashes($lexem->modelType),
                   addslashes($lexem->modelNumber),
                   addslashes($lexem->restriction),
                   addslashes($lexem->parseInfo),
                   addslashes($lexem->comment),
                   $lexem->isLoc,
                   $lexem->noAccent,
                   $lexem->createDate,
                   $lexem->modDate);
  return logged_query($query);
}

function db_updateLexem($lexem) {
  $query = sprintf("update lexems set " .
                   "lexem_forma = '%s', " .
                   "lexem_neaccentuat = '%s', " .
                   "lexem_utf8_general = '%s', " .
                   "lexem_invers = '%s', " .
                   "lexem_descr = '%s', " .
                   "lexem_model_type = '%s', " .
                   "lexem_model_no = '%s', " .
                   "lexem_restriction = '%s', " .
                   "lexem_parse_info = '%s', " .
                   "lexem_comment = '%s', " .
                   "lexem_is_loc = '%d', " .
                   "lexem_no_accent = '%d', " .
                   "CreateDate = '%d', " .
                   "ModDate = '%d' " .
                   "where lexem_id = '%d'",
                   addslashes($lexem->form),
                   addslashes($lexem->unaccented),
                   addslashes($lexem->unaccented),
                   addslashes($lexem->reverse),
                   addslashes($lexem->description),
                   addslashes($lexem->modelType),
                   addslashes($lexem->modelNumber),
                   addslashes($lexem->restriction),
                   addslashes($lexem->parseInfo),
                   addslashes($lexem->comment),
                   $lexem->isLoc,
                   $lexem->noAccent,
                   $lexem->createDate,
                   $lexem->modDate,
                   $lexem->id);
  return logged_query($query);
}

function db_deleteLexem($lexem) {
  $query = "delete from lexems where lexem_id = " . $lexem->id;
  logged_query($query);
}

function db_selectModelStatsWithSuffixes($modelType, $modelNumber) {
  $modelType = addslashes($modelType);
  $modelNumber = addslashes($modelNumber);

  $query = "select substring(lexem_invers, 1, 3) as s " .
    "from lexems, model_types " .
    "where lexem_model_type = mt_value " .
    "and mt_canonical = '$modelType' " .
    "and lexem_model_no = '$modelNumber' " .
    "group by s order by count(*) desc";
  return logged_query($query);
}

function db_selectSuffixesAndCountsForTemporaryLexems() {
  $query = "select reverse(substring(lexem_invers, 1, 4)) as s, " .
    "count(*) as c from lexems " .
    "where lexem_model_type = 'T' " .
    "group by s having c >= 5 order by c desc, s";
  return logged_query($query);
}

function db_countLabeledBySuffix($reverseSuffix) {
  $query = "select count(*) from lexems " .
    "where lexem_model_type != 'T' " .
    "and lexem_invers like '$reverseSuffix%'";
  return db_fetchInteger(logged_query($query));
}

function db_selectRestrictionsBySuffix($reverseSuffix, $tempModelId) {
  $query = "select lexem_restriction, count(*) as c  from lexems " .
    "where lexem_model != $tempModelId " .
    "and lexem_invers like '$reverseSuffix%' " .
    "group by lexem_restriction";
  return logged_query($query);
}

function db_selectModelsBySuffix($reverseSuffix) {
  $query = "select lexem_model_type, lexem_model_no, count(*) as c " .
    "from lexems " .
    "where lexem_model_type != 'T' " .
    "and lexem_invers like '$reverseSuffix%' " .
    "group by lexem_model_type, lexem_model_no order by c desc";
  return logged_query($query);
}

function db_selectTemporaryLexemsBySuffix($reverseSuffix) {
  $query = "select * from lexems where lexem_model_type = 'T' " .
    "and lexem_invers like '$reverseSuffix%' " .
    "order by lexem_neaccentuat limit 20";
  return logged_query($query);
}

function db_selectPluralLexemsByModelType($modelType) {
  if ($modelType == 'T') {
    $whereClause = 'lexem_model_type = "T"';
  } else if ($modelType) {
    $whereClause = "lexem_model_type = '{$modelType}' and lexem_restriction like '%P%'";
  } else {
    $whereClause = '(lexem_model_type = "T") or (lexem_model_type in ("M", "F", "N") and lexem_restriction like "%P%")';
  }
  return logged_query("select * from lexems where {$whereClause} order by lexem_neaccentuat");
}

function db_getLexemDefinitionMapByLexemIdDefinitionId($lexemId,
                                                       $definitionId) {
  $query = "select * from LexemDefinitionMap " .
    "where LexemId = '$lexemId' " .
    "and DefinitionId = '$definitionId'";
  return db_fetchSingleRow(logged_query($query));
}

function db_getLexemDefinitionMapsByLexemId($lexemId) {
  $query = "select * from LexemDefinitionMap " .
    "where LexemId = '$lexemId'";
  return logged_query($query);
}

function db_getLexemDefinitionMapsByDefinitionId($definitionId) {
  $query = "select * from LexemDefinitionMap " .
    "where DefinitionId = '$definitionId'";
  return logged_query($query);
}

function db_insertLexemDefinitionMap($ldm) {
  $query = sprintf("insert into LexemDefinitionMap set " .
                   "LexemId = '%d', " .
                   "DefinitionId = '%d' ",
                   $ldm->lexemId,
                   $ldm->definitionId);
  return logged_query($query);
}

function db_updateLexemDefinitionMap($ldm) {
  $query = sprintf("update LexemDefinitionMap set " .
                   "LexemId = '%d', " .
                   "DefinitionId = '%d' " .
                   "where Id = '%d'",
                   $ldm->lexemId,
                   $ldm->definitionId,
                   $ldm->id);
  return logged_query($query);
}

function db_deleteLexemDefinitionMap($ldm) {
  logged_query("delete from LexemDefinitionMap where Id = '" . $ldm->id . "'");
}

function db_deleteLexemDefinitionMapsByDefinitionId($definitionId) {
  $query = "delete from LexemDefinitionMap where DefinitionId = $definitionId";
  logged_query($query);
}

function db_deleteLexemDefinitionMapsByLexemId($lexemId) {
  $query = "delete from LexemDefinitionMap where LexemId = $lexemId";
  logged_query($query);
}

function db_deleteLexemDefinitionMapByLexemIdDefinitionId($lexemId,
                                                          $definitionId) {
  $query = "delete from LexemDefinitionMap " .
    "where LexemId = '$lexemId' " .
    "and DefinitionId = '$definitionId'";
  logged_query($query);
}

function db_deleteAllLexemDefinitionMaps() {
  $query = "delete from LexemDefinitionMap";
  logged_query($query);
}

function db_getWordListsByLexemId($lexemId) {
  $query = "select * from wordlist where wl_lexem = $lexemId " .
    "order by wl_analyse, wl_variant";
  return logged_query($query);
}

function db_getWordListByLexemIdInflectionId($lexemId, $inflectionId) {
  $query = "select * from wordlist where wl_lexem = $lexemId " .
    "and wl_analyse = $inflectionId";
  return logged_query($query);
}

function db_getWordListsByUnaccented($unaccented) {
  $unaccented = addslashes($unaccented);
  $query = "select * from wordlist where wl_neaccentuat = '$unaccented'";
  return logged_query($query);
}

function db_insertWordList($wl) {
  $query = sprintf("insert into wordlist set " .
                   "wl_form = '%s', " .
                   "wl_neaccentuat = '%s', " .
                   "wl_utf8_general = '%s', " .
                   "wl_lexem = '%d', " .
                   "wl_analyse = '%d', " .
                   "wl_variant = '%d'",
                   addslashes($wl->form),
                   addslashes($wl->unaccented),
                   addslashes($wl->unaccented),
                   $wl->lexemId,
                   $wl->inflectionId,
		   $wl->variant);
  return logged_query($query);
}

function db_deleteWordListsByLexemId($lexemId) {
  $lexemId = addslashes($lexemId);
  $query = "delete from wordlist where wl_lexem = '$lexemId'";
  return logged_query($query);
}

?>
