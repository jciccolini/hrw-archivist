<div id="branding">
	<div class="padding">
	<h1 id="logo"><a href="<?php echo $_link->to(); ?>"><?php echo SITE_TITLE; ?></a></h1>
	<div id="meta2">
		<form id="quicksearch">
			<label class="hide" for="searchinput">Search</label>
			<input type="text" id="searchinput" class="textinput" name="searchinput" />
			<input type="submit" class="submitinput" id="searchbutton" value="Search" />
		</form>
		<p id="login-blurb">Logged in as <span class="user">superuser</span>. <a href="#">Logout</a></p>
	</div>
	
<ul id="mainnav"><li><a id="nav-home" href="<?php echo $_link->to(); ?>">Home</a></li><li><a id="nav-contribute" href="<?php echo $_link->to('contribute'); ?>">Add Your Voice</a></li><li><a id="nav-browse" href="<?php echo $_link->to('browse'); ?>">Browse</a></li><li><a id="nav-myarchive" href="<?php echo $_link->to('myarchive'); ?>">MyArchive</a></li><li><a id="nav-about" href="<?php echo $_link->to('about'); ?>">About</a></li></ul>
</div>
</div>