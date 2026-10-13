<?php
include("../init.php");
header('Content-Type: application/opensearchdescription+xml; charset=utf-8');
echo <<< __HTML
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
<ShortName>Библиотека</ShortName>
<Description>Библиотека</Description>
<Tags/>
<Contact/>
<Url type="application/atom+xml" indexOffset="0" pageOffset="0" template="$webroot/opds/search?q={searchTerms}&amp;page={startPage?}"/>
<SearchForm>$webroot/opds/search</SearchForm>
<LongName>Библиотека</LongName>
<Image>/favicon.ico</Image>
<Developer/>
<Attribution/>
<SyndicationRight>open</SyndicationRight>
<AdultContent>false</AdultContent>
<Language>*</Language>
<OutputEncoding>UTF-8</OutputEncoding>
<InputEncoding>UTF-8</InputEncoding>
</OpenSearchDescription>
__HTML
?>