<?php

Class Stacey {

	static $version = '1.1';
	
	function __construct($get) {
		$this->php_fixes();
		// it's easier to handle some redirection through php rather than relying on a more complex .htaccess file to do all the work
		if($this->handle_redirects()) return;
		// parse get request
		$r = new Renderer($get);
		// handle rendering of the page
		$r->render();
	}
	
	function php_fixes() {
		// in PHP/5.3.0 they added a requisite for setting a default timezone, this should be handled via the php.ini, but as we cannot rely on this, we have to set a default timezone ourselves
		if(function_exists('date_default_timezone_set')) date_default_timezone_set('Australia/Melbourne');
	}
	
	function handle_redirects() {
		// rewrite any calls to /index or /app back to /
		if(preg_match('/index|app\/?$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ../');
			return true;
		}
		// add trailing slash if required
		if(!preg_match('/\/$/', $_SERVER['REQUEST_URI'])) {
			header('HTTP/1.1 301 Moved Permanently');
			header('Location:'.$_SERVER['REQUEST_URI'].'/');
			return true;
		}
		return false;
	}
	
}

Class Helpers {
	
	static function sort_by_length($a,$b){
		if($a == $b) return 0;
		return (strlen($a) > strlen($b) ? -1 : 1);
	}

	static function list_files($dir, $regex, $folders_only = false) {
		$files = array();		
		$glob = ($folders_only) ? glob($dir."/*", GLOB_ONLYDIR) : glob($dir."/*");
		// loop through each glob result and push it to $dirs if it matches the passed regexp 
		foreach($glob as $file) {
			// strip out just the filename
			preg_match('/\/([^\/]+?)$/', $file, $slug);
			if(preg_match($regex, $slug[1])) $files[] = $slug[1];
		}
		// sort list in reverse-numeric order
		rsort($files, SORT_NUMERIC);
		return $files;
	}
	
	static function is_category($name, $dir = '../content') {
		// check if this folder contains inner folders - if it does, then it is a category
		foreach(Helpers::list_files($dir, '/.*/', true) as $folder) {
			if(preg_match('/'.$name.'$/', $folder)) {
				$inner_folders = Helpers::list_files('../content/'.$folder, '/.*/', true);
				if(!empty($inner_folders)) return true;
			}
		}
		// if the folder doesn't contain any inner folders, then it is not a category
		return false;
	}
	
	static function path_to_url($dir) {
		return preg_replace(array('/\d+?\./', '/..\/content\//'), '', $dir);
	}
	
}

Class Cache {

	var $page;
	var $cachefile;
	var $hash;
	
	function __construct($page) {
		// store reference to current page
		$this->page = $page;
		// turn a base64 of the full path to the page's content file into the name of the cache file
		$this->cachefile = './cache/'.base64_encode($this->page->content_file);
		//collect an md5 of all files
		$this->hash = $this->create_hash();
	}
	
	function check_expired() {
		// if cachefile doesn't exist, we need to create one
		if(!file_exists($this->cachefile)) return true;
		// compare new m5d to existing cached md5
		elseif($this->hash !== $this->get_current_hash()) return true;
		else return false;
	}
	
	function get_current_hash() {
		preg_match('/Stacey.*: (.+?)\s/', file_get_contents($this->cachefile), $matches);
		return $matches[1];
	}
	
	function write_cache() {
		echo "\n".'<!-- Stacey('.Stacey::$version.'): '.$this->hash.' -->';
		$fp = fopen($this->cachefile, 'w');
		fwrite($fp, ob_get_contents());
		fclose($fp);
	}

	function create_hash() {
		// create a collection of every file inside the content folder
		$content = $this->collate_files('../content/');
		// create a collection of every file inside the templates folder
		$templates = $this->collate_files('../templates/');
		// create an md5 of the two collections
		return $this->hash = md5($content.$templates);
	}
	
	function collate_files($dir) {
		if(!isset($files_modified)) $files_modified = '';
		foreach(Helpers::list_files($dir, '/.*/') as $file) {
			$files_modified .= $file.':'.filemtime($dir.'/'.$file);
			if(is_dir($dir.'/'.$file)) $this->collate_files($dir.'/'.$file);
		}
		return $files_modified;
	}
	
}


Class Renderer {
	
	var $page;
	
	function __construct($get) {
		// take the passed url ($get) and turn it into an object
		$this->page = $this->handle_routes(key($get));
	}
	
	function handle_routes($path) {
		
		// if path is empty, we're looking for the index page
		if($path == '') { $path = 'index'; }

		// if key does contain slashes, it must be a page within a category
		else if(preg_match('/\//', $path)) {
			return new PageInCategory($path);
		}
		// if key contains no slashes, it must be a page or a category
		else if(Helpers::is_category($path)) {
			return new Category($path);
		}
		
		return new Page($path);
		
		
	}
	
	function render_404() {
		// return correct 404 header
		header('HTTP/1.0 404 Not Found');
		// if there is a 404 page set, use it
		if(file_exists('../public/404.html')) echo file_get_contents('../public/404.html');
		// otherwise, use this text as a default
		else echo '<h1>404</h1><h2>Page could not be found.</h2><p>Unfortunately, the page you were looking for does not exist here.</p>';
	}
	
	function render() {
		// if page doesn't contain a content file or have a matching template file, redirect to it or return 404
		if(!$this->page || !$this->page->template_file) {
			// if a static html page with a name matching the current route exists in the public folder, serve it 
			if($this->page->public_file) echo file_get_contents($this->page->public_file);
			// serve 404
			else $this->render_404();
		} else {
			// create new cache object
			$cache = new Cache($this->page);
			// check etags
			header ('Etag: "'.$cache->hash.'"');
			if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) == '"'.$cache->hash.'"') {
				// local cache is still fresh, so return 304
				header ("HTTP/1.0 304 Not Modified");
				header ('Content-Length: 0');
			} else {
				// check if cache needs to be expired
				if($cache->check_expired()) {
					// start output buffer
					ob_start();
						// render page
						$t = new TemplateParser;
						$c = new ContentParser;
						echo $t->parse($this->page, $c->parse($this->page));
						// cache folder is writable, write to it
						if(is_writable('./cache')) $cache->write_cache();
						else echo "\n".'<!-- Stacey('.Stacey::$version.'). -->';
					// end buffer
					ob_end_flush();
				} else {
					// else cache hasn't expired, so use existing cache
					echo file_get_contents($cache->cachefile)."\n".'<!-- Cached. -->';
				}
			}
		}
	}
}

Class Page {
	var $name;

	var $parent_url;
	
	var $content_file;
	var $template_file;
	var $public_file;
	
	var $image_files = array();
	var $video_files = array();
	var $html_files = array();
	var $swf_files = array();
	
	var $default_template = 'content';

	var $path;
	var $link_path;
	
	var $children;
	var $siblings;
	var $position;
	var $page_type;
	var $sibling_pages; 
	var $url;
	
	function __construct($url = 'index') {
		
		// parse the url /$parent_url/$name
		preg_match("/^((.*)\/)?([^\/]*$)/", $url, $url_matches);
		$this->url = $url;
		$this->parent_url = $url_matches[2];
		$this->name = $url_matches[3];
		
		$this->path = $this->get_path();
		$this->link_path = $this->construct_link_path();
		
		$this->content_file = $this->get_content_file();
		$this->template_file = $this->get_template_file($this->default_template);
		$this->public_file = $this->get_public_file();
		$this->image_files = $this->get_assets('/\.(gif|jpg|png|jpeg)/i');
		$this->video_files = $this->get_assets('/\.(mov|mp4)/i');
		$this->html_files = $this->get_assets('/\.(html|htm)/i');
		$this->swf_files = $this->get_assets('/\.swf/i');
						
		$this->siblings = Helpers::list_files($this->path.'/../', '/.*/', true);
		
		// Save the position of the page within its siblings 
		foreach($this->siblings as $key => $file) {
			if(preg_match('/^\d+?\.'.$this->name.'$/', $file)) {
				$this->position = $key;
				break;
			}
		}
		
		$this->children = Helpers::list_files($this->path, '/.*/', true);

		$this->sibling_pages = $this->get_sibling_pages();
		
	}
	function debug() {
		$html .= '<p>Type: '.$this->get_page_type().'</p>';
		$html .= '<p>Path: '.$this->path.' | '.$this->link_path.'</p>';
		$html .= '<p>Template: '.$this->template_file.'</p>';
		$html .= '<p>Name: '.$this->name.'<p>';
		$html .= '<p>Parent: <a href="'.$this->link_path.$this->parent_url.'">'.$this->parent_url.'</a></p>';
		
		$html .= "<p>URL: ".$this->url."</p>";
		foreach($this->children as $a) {
			$html .= '<p>C: '.$a.'</p>';
		}
		foreach($this->siblings as $n) { $html .= '<p>'.$n.'</p>'; }
		
		return $html;
	}

	
	function clean_name($name) {
		// strip leading digit and dot from filename (1.xx becomes xx)
		return preg_replace('/^\d+?\./', '', $name);
	}
	
	function unclean_name($name, $dir) {
		$matches = Helpers::list_files($dir, '/^\d+?\.'.$name.'$/', true);
		return (!empty($name) && !empty($matches)) ? $matches[0] : false;
	}
	
	function get_assets($regex = '/.*/') {
		// get containing directory by stripping the content file path
		$dir = $this->folder_path;
		// store a list of all image files
		$files = Helpers::list_files($dir, $regex);
		// remove any thumbnails from the array
		foreach($files as $key => $file) if(preg_match('/thumb\./i', $file)) unset($files[$key]);
		return $files;
	}
	
	function get_thumb() {
		$thumbs = Helpers::list_files($this->path, '/thumb\.(gif|jpg|png|jpeg)/i');
		return (!empty($thumbs)) ? $this->path.'/'.$thumbs[0]  : '';
	}
	
	function is_current() {
		// Check if the url of this page matches the REQUEST_URI
		return preg_match('/'.preg_replace('/\//', '\/', $this->url).'\/?$/', $_SERVER['REQUEST_URI']);
	}
	
	function get_template_file($default_template) {
		// find the name of the text file
		$page_type = $this->get_page_type();

		// check folder exists, if not, return 404
		if(!$page_type) return false;

		// if template exists, return it
		if(file_exists('../templates/'.$page_type.'.html')) return '../templates/'.$page_type.'.html';
		// return content.html as default template (if it exists)
		elseif(file_exists('../templates/'.$default_template.'.html')) return '../templates/'.$default_template.'.html';
		else return false;
	}
	
	function construct_link_path() {
		if ($this->get_page_type() == 'index') return '';
		$depth = sizeof(explode('/',$this->url));
		return str_repeat("../", $depth);
	}
	
	function get_path() {
		$path = '../content';
		
		// Split the categories and recursively unclean the categories into folder names
		$categories = explode('/', $this->parent_url);
		foreach($categories as $c) {
			$path .= (!empty($c)) ? '/'.$this->unclean_name($c,$path) : '';
		}
		
		$path .= '/'.$this->unclean_name($this->name,$path);
		
		return $path;
	}

	function get_page_type() {
		$txts = Helpers::list_files($this->path, '/\.txt$/');

		return (!empty($txts)) ? preg_replace('/\.txt/', '', $txts[0]) : false;
	}

	function get_content_file() {
		// look for a .txt file
		$file = $this->path.'/'.$this->get_page_type().'.txt';

		if (file_exists($file)) return $file;
		else return $this->path.'/none';
	}
	
	function get_public_file() {
		// see if a static html file with $name exists in the public folder
		if(file_exists('../public/'.$this->name.'.html')) return '../public/'.$this->name.'.html';
		else return false;
	}

	function get_sibling_pages() {
		$total = count($this->siblings);
		$i = $this->position;
				
		// Don't display links if there are no other pages
		if ($total <= 1) return;

		// store the names of the next/previous pages
		$prev_name = $this->clean_name($this->siblings[ ($i == 0) ? $total-1 : $i-1 ]);
		$next_name = $this->clean_name($this->siblings[ ($i+1) % $total] );
		
		//store the urls of the next/previous pages
		$prev = array('/@url/' => '../'.$prev_name);
		$next = array('/@url/' => '../'.$next_name);
		
		// create MockPageInCategory objects so we can access the variables of the pages
		$prev_page = new MockPageInCategory($this->parent_url.'/'.$prev_name);
		$next_page = new MockPageInCategory($this->parent_url.'/'.$next_name);
				 
		$c = new ContentParser;
		return array(
			array_merge($prev, $c->parse($prev_page)),
			array_merge($next, $c->parse($next_page)),
		);	
	}	
}

Class Category extends Page {
	var $default_template = 'category';
}

Class PageInCategory extends Page {
	var $default_template = 'page-in-category';
}

Class MockPageInCategory extends PageInCategory {
	function get_sibling_pages() {
		// escape this function (to prevent infinite loop)
		return array(array(), array());
	}
}

Class ContentParser {
	
	var $page;
	
	function preparse($text) {
		$patterns = array(
			// replace inline colons
			'/(?<=\n)([a-z0-9_-]+?):(?!\/)/',
			'/:/',
			'/\\\x01/',
			// replace inline dashes
			'/(?<=\n)-/',
			'/-/',
			'/\\\x02/',
			// automatically link http:// websites
			'/(?<![">])\bhttp&#58;\/\/([\S]+\.[\S]*\.?[A-Za-z0-9]{2,4})/',
			// automatically link email addresses
			'/(?<![;>])\b([A-Za-z0-9.-]+)@([A-Za-z0-9.-]+\.[A-Za-z]{2,4})/',
			// convert lists
			'/\n?-(.+?)(?=\n)/',
			'/(<li>.*<\/li>)/',
			// replace doubled lis
			'/<\/li><\/li>/',
			// replace headings h1. h2. etc
			'/h([0-5])\.\s?(.*)/',
			// wrap multi-line text in paragraphs
			'/([^\n]+?)(?=\n)/',
			'/<p>(.+):(.+)<\/p>/',
			'/: (.+)(?=\n<p>)/',
			// replace any keys that got wrapped in ps
			'/(<p>)([a-z0-9_-]+):(<\/p>)/',
			// replace any headings that got wrapped in ps
			'/<p>(<h[0-5]>.*<\/h[0-5]>)<\/p>/'
		);
		$replacements = array(
			// replace inline colons
			'$1\\x01',
			'&#58;',
			':',
			// replace inline dashes
			'\\x02',
			'&#45;',
			'-',
			// automatically link http:// websites
			'<a href="http&#58;//$1">http&#58;//$1</a>',
			// automatically link email addresses
			'<a href="mailto&#58;$1&#64;$2">$1&#64;$2</a>',
			// convert lists
			'<li>$1</li>',
			'<ul>$1</ul>',
			// replace doubled lis
			'</li>',
			// replace headings h1. h2. etc
			'<h$1>$2</h$1>',
			// wrap multi-line text in paragraphs
			'<p>$1</p>',
			'$1:$2',
			':<p>$1</p>',
			// replace any keys that got wrapped in ps
			'$2:',
			'$1'
		);
		$parsed_text = preg_replace($patterns, $replacements, $text);
		return $parsed_text;
	}
	
	function create_replacement_rules($text) {
				
		// push additional useful values to the replacement pairs
		$replacement_pairs = array(
			'/@Images_Count/' => count($this->page->image_files),
			'/@Video_Count/' => count($this->page->video_files),
			'/@Html_Count/' => count($this->page->html_files),
			'/@Swfs_Count/' => count($this->page->swf_files),
			'/@Media_Count/' => count($this->page->image_files) + count($this->page->video_files) + count($this->page->html_files) + count($this->page->swf_files),
			
			'/@Pages_Count/' => count($this->page->siblings),
			'/@Page_Number/' => $this->page->position + 1,
			'/@Year/' => date('Y'),
			'/@Site_Root\/?/' =>  $this->page->link_path,

			'/@Debug/' => $this->page->debug(),
			'/@Previous_Page/' => Partial::render($this->page, null, '../templates/partials/previous-page.html', 'PreviousPage'),
			'/@Next_Page/' => Partial::render($this->page, null, '../templates/partials/next-page.html', 'NextPage')
		);
		// if the page is a Category, push category-specific variables
		if(get_class($this->page) == 'Category' || get_class($this->page) == 'PageInCategory') {
			// look for a partial file matching the categories name, otherwise fall back to using the category partial
			$partial_file = file_exists('../templates/partials/'.$this->page->name.'.html') ? '../templates/partials/'.$this->page->name.'.html' : '../templates/partials/category-list.html';
			// create a dynamic category list variable
			$replacement_pairs['/@Category_List/'] = Partial::render($this->page, $this->page->path, $partial_file, 'CategoryList');
		}
		
		// pull out each key/value pair from the content file
		preg_match_all('/[\w\d_-]+?:[\S\s]*?\n\n/', $text, $matches);
		foreach($matches[0] as $match) {
			$colon_split = explode(':', $match);
			$replacement_pairs['/@'.$colon_split[0].'/'] = trim($colon_split[1]);
		}
		// sort keys by length, to ensure replacements are made in the correct order (ie. @page does not partially replace @page_name)
		uksort($replacement_pairs, array('Helpers', 'sort_by_length'));
		return $replacement_pairs;
	}
	
	function parse($page) {
		// store page and parse its content file
		$this->page = $page;
		// store contents of content file (if it exists, otherwise, pass back an empty string)
		$text = (file_exists($this->page->content_file)) ? file_get_contents($this->page->content_file) : '';
		// include shared variables for each page
		$shared = (file_exists('../content/_shared.txt')) ? file_get_contents('../content/_shared.txt') : '';
		// run preparsing rules to clean up content files (the newlines are added to ensure the first and last rules have their double-newlines to match on)
		$parsed_text = $this->preparse("\n\n".$text."\n\n".$shared."\n\n");
		// create the replacement rules
		return $this->create_replacement_rules($parsed_text);
	}
	
}

Class TemplateParser {

	var $page;
	var $replacement_pairs;
	
	function find_categories() {
		$dir = '../content/';
		$categories = array();
	
		// loop through each top-level folder to check if it contains other folders (in which case it is a category);
		foreach(Helpers::list_files($dir, '/.*/', true) as $folder) {
				$inner_folders = Helpers::list_files('../content/'.$folder, '/.*/', true);
				if(!empty($inner_folders)) {
					// strip leading digit and dot from filename (1.xx becomes xx)
					$folder_clean = preg_replace('/^\d+?\./', '', $folder);
					$categories[$folder] = array(
						'name' => $folder,
						'name_clean' => $folder_clean,
						// look for a partial file matching the categories name, otherwise fall back to using the category partial
						'partial_file' => file_exists('../templates/partials/'.$folder_clean.'.html') ? '../templates/partials/'.$folder_clean.'.html' : '../templates/partials/category-list.html'
					);
				}
		}
		return $categories;
	}
	
	function create_replacement_partials() {
		
		// construct a special variable which will hold all of the category lists
		$partials['/@Category_Lists/'] = '';
		// find all categories
		$categories = $this->find_categories();
		// category lists will become available as a variable as: '$.projects-folder' => @Projects_Folder
		foreach($categories as $category) {
			// store the output of the CategoryListPartial
			$category_list = Partial::render($this->page, $category['name'], $category['partial_file'], 'CategoryList');
			// create a partial that matches the name of the category
			$partials['/@'.ucfirst(preg_replace('/-(.)/e', "'_'.strtoupper('\\1')", $category['name_clean'])).'/'] = $category_list;
			// append to the @Category_Lists variable
			$partials['/@Category_Lists/'] .= $category_list;
		}
		// construct the rest of the special variables
		$partials['/@Navigation/'] = Partial::render($this->page, '../content/', '../templates/partials/navigation.html', 'Navigation');
		$partials['/@Pages/'] = Partial::render($this->page, '../content/', '../templates/partials/pages.html', 'Pages');
		
		// construct asset variables
		$partials['/@Images/'] = Partial::render($this->page, null, '../templates/partials/images.html', 'Images');
		$partials['/@Video/'] = Partial::render($this->page, null, '../templates/partials/video.html', 'Video');
		$partials['/@Html/'] = Partial::render($this->page, null, null, 'Html');
		$partials['/@Swfs/'] = Partial::render($this->page, null, '../templates/partials/swf.html', 'Swf');
		$partials['/@Media/'] = $partials['/@Images/'].$partials['/@Video/'].$partials['/@Swfs/'].$partials['/@Html/'];

		return $partials;
	}
	
	function parse($page, $rules) {
		// store reference to current page
		$this->page = $page;
		// create all the replacement pairs that rely on partials
		$this->replacement_pairs = array_merge($rules, $this->create_replacement_partials());
		// sort keys by length, to ensure replacements are made in the correct order (ie. @page does not partially replace @page_name)
		uksort($this->replacement_pairs, array('Helpers', 'sort_by_length'));
		// store template file content
		$text = file_get_contents($this->page->template_file);
		// run replacements on the template
		return preg_replace(array_keys($this->replacement_pairs), array_values($this->replacement_pairs), $text);
	}
}

Class Partial {
	
	var $page;
	var $partial_file;

	static function get_partial($partial_file) {
		$partial = (file_exists($partial_file)) ? file_get_contents($partial_file) : '<p>! '.$partial_file.' not found.</p>';
		// split the template file by loop code
		preg_match('/([\S\s]*)foreach[\S\s]*?:([\S\s]*)endforeach;([\S\s]*)/', $partial, $matches);
		// if partial file found, return array containing the markup: before loop, inside loop & after loop (in that order)
		if(!empty($matches)) return array($matches[1], $matches[2], $matches[3]);
		// if partial file not found, return warning string
		else return array('', $partial, '');
	}
	
	static function render($page, $dir, $partial_file, $partial_type) {
		// get partial file contents if a partial file was passed through
		$wrappers = ($partial_file) ? self::get_partial($partial_file) : array('', '', '');
		$html = '';
		// add outer wrapper
		$html .= $wrappers[0];
		// if a partial is passed through, then we want to process any loops inside it
		$html .= call_user_func_array($partial_type.'::parse_loop', array($page, '../content/'.$dir, $wrappers[1]));
		// add closing wrapper
		
		$html .= $wrappers[2];
		return $html;
		
	}
	
}

Class CategoryList extends Partial {
	
	static function parse_loop($page, $dir, $loop_html) {
		$files = Helpers::list_files($dir, '/^\d+?\./', true);
		$html = '';
		
		
		foreach($files as $key => $file) {
			
			// for each page within this category...
			$url = Helpers::path_to_url($dir.'/'.$file);
			
			$c = new ContentParser;
			$category_page = new MockPageInCategory($url);
						
			$vars = array(
				'/@url/' => $page->link_path.$url,
				'/@thumb/' => $category_page->get_thumb(),
				'/@css_class/' => $category_page->is_current() ? 'active' : '',
			);

			// create a MockPageInCategory to give us access to all the variables inside this PageInCategory
			$vars = array_merge($vars, $c->parse($category_page));
			$html .= preg_replace(array_keys($vars), array_values($vars), $loop_html);
		}
		
		return $html;
	}
	
}

Class Navigation extends Partial {
	
	static function parse_loop($page, $dir, $loop_html) {
		$files = Helpers::list_files($dir, '/^\d+?\./', true);
		$path = $page->link_path.preg_replace(array('/\.\.\/content\//', '/^\d+?\./'), '', $dir);
		$html = '';
		foreach($files as $key => $file) {
			// if file is not the index, add it to the navigation list
			if (!preg_match('/index/', $file)) {
				$file_name_clean = preg_replace('/^\d+?\./', '', $file);
				// store the url and name of the navigation item
				$replacements = array(
					'/@url/' => $path.$file_name_clean.'/',
					'/@name/' => ucfirst(preg_replace('/-/', ' ', $file_name_clean)),
					'/@css_class/' => preg_match('/'.preg_replace('/\//', '\/', $file_name_clean).'/', $_SERVER['REQUEST_URI']) ? 'active' : ''
				);
				
				$html .= preg_replace(array_keys($replacements), array_values($replacements), $loop_html);
			}
		}
		
		return $html;
	}
	
}

Class Pages extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$files = Helpers::list_files($dir, '/^\d+?\./', true);
		$html = '';
		foreach($files as $key => $file) {
			// if file is not a category and is not the index page, add it to the pages list
			if (!preg_match('/index/', $file) && !Helpers::is_category($file, $dir)) {
				$file_name_clean = preg_replace('/^\d+?\./', '', $file);
				// store the url and name of the navigation item
				$replacements = array(
					'/@url/' => $page->link_path.$file_name_clean.'/',
					'/@name/' => ucfirst(preg_replace('/-/', ' ', $file_name_clean)),
				);

				$html .= preg_replace(array_keys($replacements), array_values($replacements), $loop_html);
			}
		}
		
		return $html;
	}
	
}

Class Images extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$dir = $page->link_path.preg_replace('/\.\.\//', '', $page->path);

		$html = '';
		
		$files = $page->image_files;
		foreach($files as $key => $file) {
			$html .= preg_replace('/@url/', $dir.'/'.$file, $loop_html);
		}
		
		return $html;
	}

}

Class Video extends Partial {
	
	static function parse_loop($page, $dir, $loop_html) {
		$dir = $page->link_path.preg_replace('/\.\.\//', '', $page->path);

		$html = '';
		
		$files = $page->video_files;
		foreach($files as $key => $file) {
			// pull dimensions from file name (if they exist)
			if(preg_match('/(\d+?)x(\d+?)\./', $file, $matches)) $dimensions = array('width' => $matches[1], 'height' => $matches[2]);
			else $dimensions = array('width' => '', 'height' => '');
			$html .= preg_replace(array(
				'/@url/',
				'/@width/',
				'/@height/'
			), array(
				$dir.'/'.$file,
				$dimensions['width'],
				$dimensions['height']
			), $loop_html);
		}
		
		return $html;
	}

}

Class Swf extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$dir = $page->link_path.preg_replace('/\.\.\//', '', $page->path);

		$html = '';
		
		$files = $page->swf_files;
		foreach($files as $key => $file) {
			// pull dimensions from file name (if they exist)
			if(preg_match('/(\d+?)x(\d+?)\./', $file, $matches)) $dimensions = array('width' => $matches[1], 'height' => $matches[2]);
			else $dimensions = array('width' => '', 'height' => '');
			$html .= preg_replace(array(
				'/@url/',
				'/@width/',
				'/@height/'
			), array(
				$dir.'/'.$file,
				$dimensions['width'],
				$dimensions['height']
			), $loop_html);
		}			
		return $html;
	}

}

Class Html extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$dir = $page->link_path.preg_replace('/\.\.\//', '', $page->path);
		$html = '';
		
		$files = $page->html_files;
		foreach($files as $key => $file) {
			if(is_readable($dir.'/'.$file)) $html .= file_get_contents($dir.'/'.$file);
		}
		return $html;
	}

}

Class NextPage extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$page_sibling = $page->sibling_pages[1];
		$html = (!empty($page_sibling)) ? preg_replace(array_keys($page_sibling), array_values($page_sibling), $loop_html) : '';
		return $html;
	}

}

Class PreviousPage extends Partial {

	static function parse_loop($page, $dir, $loop_html) {
		$page_sibling = $page->sibling_pages[0];
		$html = (!empty($page_sibling)) ? preg_replace(array_keys($page_sibling), array_values($page_sibling), $loop_html) : '';
		return $html;
	}

}


?>