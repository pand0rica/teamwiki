<html xml:lang="de" lang="de" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title>{$mybb->settings['bbname']} - {$lang->teamwiki}</title>
    {$headerinclude}
</head>
<body>
{$header}
<div class="panel" id="panel">
	<table width="100%">
		<tr>
		    <td class="thead" colspan="3"><strong>{$lang->teamwiki}</strong></td>
	    </tr>
		<tr>
		    <td class="tcat" width="200px;"><strong>{$lang->teamwiki_menu}</strong></td>
			<td width="20px;"></td>
		    <td class="tcat"><strong>{$entry['title']}</strong></td>
	    </tr>
		<tr>
		    <td valign="top">
				{$teamwiki_menu}
			</td>
			<td></td>
		    <td valign="top" class="teamwiki-content">
				{$teamwiki_content}
			</td>
	    </tr>
	</table>
</div>
{$footer}
</body>
</html>