<?php
/**
*
* @package rank_color_system_mod
* @version $Id: template.php,v 0.1 28/11/2006 14:17 reddog Exp $
* @copyright (c) 2005 phpBB Group, (c) 2006 reddog - http://www.reddevboard.com/
* @license http://opensource.org/licenses/gpl-license.php GNU Public License 
*
*/

/**
* Template class. By Nathan Codding of the phpBB group.
* The interface was inspired by PHPLib templates, and the template file
* (formats are quite similar)
*
* The compile part borrowed from Categories Hierarchy 2.1.4 by Ptirhiik
* is in its greatest part a simplified version of phpBB 2.1.x (aka Olympus)
* template engine, so credits for good things go to PsoTFX
*/

if ( !defined('IN_PHPBB') )
{
	die('Hacking attempt');
}

class Template
{
	/** variable that holds all the data we'll be substituting into
	* the compiled templates. Takes form:
	* --> $this->_tpldata[block.][iteration#][child.][iteration#][child2.][iteration#][variablename] == value
	* if it's a root-level variable, it'll be like this:
	* --> $this->_tpldata[.][0][varname] == value
	*/
	var $_tpldata = array();

	// Root dir and hash of filenames for each template handle.
	var $root = '';
	var $cachepath = '';
	var $files = array();

	// this will hash handle names to the compiled/uncompiled code for that handle.
	var $compiled_code = array();

	// This will hold the uncompiled code for that handle.
	var $uncompiled_code = array();

	// Various storage arrays
	var $block_names = array();
	var $block_else_level = array();

	function template($root='.')
	{
		$this->set_rootdir($root);
	}

	/**
	* Destroy template data set
	* @access public
	*/
	function destroy()
	{
		$this->_tpldata = array();
	}

	/**
	* Sets the template root directory for this Template object.
	* @access public
	*/
	function set_rootdir($dir)
	{
		if ( !@is_dir($dir) )
		{
			return false;
		}

		$dir = str_replace('\\', '/', $dir);
		$this->root = $dir;

		return true;
	}

	/**
	* Sets the template filenames for handles. $filename_array
	* should be a hash of handle => filename pairs.
	* @access public
	*/
	function set_filenames($filename_array)
	{
		if ( !is_array($filename_array) )
		{
			return false;
		}

		foreach( $filename_array as $handle => $filename )
		{
			$this->files[$handle] = $this->make_filename($filename);
		}

		return true;
	}

	/**
	* Load the file for the handle, compile the file,
	* and run the compiled code. This will print out
	* the results of executing the template.
	* @access public
	*/
	function pparse($handle)
	{
		if ( !$this->loadfile($handle) )
		{
			die("Template->pparse(): Couldn't load template file for handle $handle");
		}

		// actually compile the template now.
		if ( !isset($this->compiled_code[$handle]) || empty($this->compiled_code[$handle]) )
		{
			// Actually compile the code now.
			$this->compiled_code[$handle] = $this->compile($this->uncompiled_code[$handle]);
		}

		// Run the compiled code.
		eval($this->compiled_code[$handle]);

		return true;
	}

	/**
	* Inserts the uncompiled code for $handle as the
	* value of $varname in the root-level. This can be used
	* to effectively include a template in the middle of another
	* template.
	* Note that all desired assignments to the variables in $handle should be done
	* BEFORE calling this function.
	* @access public
	*/
	function assign_var_from_handle($varname, $handle)
	{
		if ( !$this->loadfile($handle) )
		{
			die("Template->assign_var_from_handle(): Couldn't load template file for handle $handle");
		}

		// Compile it, with the "no echo statements" option on.
		$_str = '';
		$code = $this->compile($this->uncompiled_code[$handle], true, '_str');

		// evaluate the variable assignment.
		eval($code);

		// assign the value of the generated variable to the given varname.
		$this->assign_vars(array($varname => $_str));

		return true;
	}

	/**
	* Block-level variable assignment. Adds a new block iteration with the given
	* variable assignments. Note that this should only be called once per block
	* iteration.
	* @access public
	*/
	function assign_block_vars($blockname, $vararray)
	{
		if ( strstr($blockname, '.') )
		{
			// Nested block
			$blocks = explode('.', $blockname);
			$blockcount = sizeof($blocks) - 1;

			$str = &$this->_tpldata;
			for ( $i = 0; $i < $blockcount; $i++ )
			{
				$str = &$str[$blocks[$i].'.'];
				$str = &$str[sizeof($str)-1];
			}
			// Now we add the block that we're actually assigning to.
			// We're adding a new iteration to this block with the given
			// variable assignments.
			$str[$blocks[$blockcount].'.'][] = $vararray;
		}
		else
		{
			// Top-level block
			// Add a new iteration to this block with the variable assignments
			// we were given.
			$this->_tpldata[$blockname.'.'][] = $vararray;
		}

		return true;
	}

	/**
	* Assign key variable pairs from an array
	* @access public
	*/
	function assign_vars($vararray)
	{
		foreach ( $vararray as $key => $val )
		{
			$this->_tpldata['.'][0][$key] = $val;
		}

		return true;
	}

	/**
	* Generates a full path+filename for the given filename, which can either
	* be an absolute name, or a name relative to the rootdir for this Template
	* object.
	* @access private
	*/
	function make_filename($filename)
	{
		// Check if it's an absolute or relative path.
		if ( substr($filename, 0, 1) != '/' )
		{
			$filename = ( $rp_filename = phpbb_realpath($this->root . '/' . $filename) ) ? $rp_filename : $filename;
		}

		if ( !file_exists($filename) )
		{
			die("Template->make_filename(): Error - file $filename does not exist");
		}

		return $filename;
	}

	/**
	* If not already done, load the file for the given handle and populate
	* the uncompiled_code[] hash with its code. Do not compile.
	* @access private
	*/
	function loadfile($handle)
	{
		// If the file for this handle is already loaded and compiled, do nothing.
		if ( !empty($this->uncompiled_code[$handle]) )
		{
			return true;
		}

		// If we don't have a file assigned to this handle, die.
		if ( !isset($this->files[$handle]) || empty($this->files[$handle]) )
		{
			die("Template->loadfile(): No file specified for handle $handle");
		}

		$filename = $this->files[$handle];
		$str = implode('', @file($filename));

		if ( empty($str) )
		{
			die("Template->loadfile(): File $filename for handle $handle is empty");
		}

		$this->uncompiled_code[$handle] = $str;

		return true;
	}

	/**
	* Include a seperate template
	* @access private
	*/
	function _tpl_include($filename)
	{
		if ( !empty($filename) )
		{
			$this->set_filenames(array($filename => $filename));
			$this->pparse($filename);
		}
	}

	/**
	* Compiles the given string of code, and returns
	* the result in a string.
	* If "do_not_echo" is true, the returned code will not be directly
	* executable, but can be used as part of a variable assignment
	* for use in assign_code_from_handle().
	* @access private
	*/
	function compile($code, $do_not_echo = false, $retvar = '')
	{
		// escape code
		$code = str_replace('\\', '\\\\', $code);
		$code = str_replace('\'', '\\\'', $code);

		// remove also tabulations
		$code = preg_replace("/([\n\r])([\s\t]*)/", '\1', $code);

		// split in block
		preg_match_all('#<!-- (.*?) (.*?)?[ ]?-->#s', $code, $blocks);
		$text_blocks = preg_split('#<!-- (.*?) (.*?)?[ ]?-->#s', $code);
		for ( $i = 0; $i < count($text_blocks); $i++ )
		{
			$this->compile_var_tags($text_blocks[$i]);
		}

		// analyse tags
		$compile_blocks = array();
		$count_text_blocks = count($text_blocks);
		for ( $i = 0; $i < $count_text_blocks; $i++ )
		{
			switch ( $blocks[1][$i] )
			{
				case 'BEGIN':
					$this->block_else_level[] = false;
					$compile_blocks[] = $this->compile_tag_block($blocks[2][$i]);
					break;

				case 'BEGINELSE':
					$this->block_else_level[ sizeof($this->block_else_level) - 1 ] = true;
					$compile_blocks[] = '}} else {';
					break;

				case 'END':
					array_pop($this->block_names);
					$compile_blocks[] = ((array_pop($this->block_else_level)) ? '}' : '}}') . "\n";
					break;

				case 'INCLUDE':
					$compile_blocks[] = '$this->_tpl_include(\'' . trim($blocks[2][$i]) . '\');' . "\n";
					break;

				default:
					$text_block = $this->compile_var_tags($blocks[0][$i]);
					$text_blocks[$i] .= (trim($text_block) == '') ? '' : "\n" . $text_block;
					$compile_blocks[] = '';
					break;
			}
		}

		// build result
		$template_php = '';
		$count_text_blocks = count($text_blocks);
		for ( $i = 0; $i < $count_text_blocks; $i++ )
		{
			// remove orphean lines when appropriate
			$text_block = preg_replace("/^([\n\r]{2,})/", '', $text_blocks[$i]);
			$template_php .= (trim($text_block) == '' ? '' : ($do_not_echo ? '$' . $retvar . ' .= ' : 'echo ') . '\'' . $text_block . '\';' . "\n") . trim($compile_blocks[$i]);
		}

		// There will be a number of occassions where we switch into and out of
		// PHP mode instantaneously. Rather than "burden" the parser with this
		// we'll strip out such occurences, minimising such switching
		return str_replace(' ?><?php ', '', $template_php);
	}

	/**
	* Compile variables
	* @access private
	*/
	function compile_var_tags(&$text_blocks)
	{
		// change template varrefs into PHP varrefs
		$varrefs = array();

		// This one will handle varrefs WITH namespaces
		preg_match_all('#\{(([a-z0-9\-_]+?\.)+?)([a-z0-9\-_]+?)\}#is', $text_blocks, $varrefs);

		$count_varrefs_1 = count($varrefs[1]);
		for ( $j = 0; $j < $count_varrefs_1; $j++ )
		{
			$namespace = $varrefs[1][$j];
			$varname = $varrefs[3][$j];
			$new = '\' . ' . $this->generate_block_varref($namespace, $varname) . ' . \'';

			$text_blocks = str_replace($varrefs[0][$j], $new, $text_blocks);
		}

		// This will handle the remaining root-level varrefs
		$text_blocks = preg_replace('#\{([a-z0-9\-_]*?)\}#is', "' . \$this->_tpldata['.'][0]['\\1'] . '", $text_blocks);

		return $text_blocks;
	}

	/**
	* Compile blocks
	* @access private
	*/
	function compile_tag_block($tag_args)
	{
		// Allow for control of looping (indexes start from zero):
		// foo(2)    : Will start the loop on the 3rd entry
		// foo(-2)   : Will start the loop two entries from the end
		// foo(3,4)  : Will start the loop on the fourth entry and end it on the fourth
		// foo(3,-4) : Will start the loop on the fourth entry and end it four from last
		if ( preg_match('#^(.*?)\(([\-0-9]+)(,([\-0-9]+))?\)$#', $tag_args, $match) )
		{
			$tag_args = $match[1];
			$loop_start = ($match[2] < 0) ? '$_' . $tag_args . '_count ' . ($match[2] - 1) : $match[2];
			$loop_end = ($match[4]) ? (($match[4] < 0) ? '$_' . $tag_args . '_count ' . $match[4] : ($match[4] + 1)) : '$_' . $tag_args . '_count';
		}
		else
		{
			$loop_start = 0;
			$loop_end = '$_' . $tag_args . '_count';
		}

		$tag_template_php = '';
		array_push($this->block_names, $tag_args);

		if ( sizeof($this->block_names) < 2 )
		{
			// Block is not nested.
			$tag_template_php = '$_' . $tag_args . "_count = (isset(\$this->_tpldata['$tag_args.'])) ?  sizeof(\$this->_tpldata['$tag_args.']) : 0;";
		}
		else
		{
			// This block is nested.

			// Generate a namespace string for this block.
			$namespace = implode('.', $this->block_names);

			// Get a reference to the data array for this block that depends on the
			// current indices of all parent blocks.
			$varref = $this->generate_block_data_ref($namespace, false);

			// Create the for loop code to iterate over this block.
			$tag_template_php = '$_' . $tag_args . '_count = (isset(' . $varref . ')) ? sizeof(' . $varref . ') : 0;';
		}

		$tag_template_php .= "\n" . 'if ($_' . $tag_args . '_count) {';
		$tag_template_php .= "\n" . 'for ($_' . $tag_args . '_i = ' . $loop_start . '; $_' . $tag_args . '_i < ' . $loop_end . '; $_' . $tag_args . '_i++){' . "\n";

		return $tag_template_php;
	}

	/**
	* Generates a reference to the given variable inside the given (possibly nested)
	* block namespace. This is a string of the form:
	* ' . $this->_tpldata['parent'][$_parent_i]['$child1'][$_child1_i]['$child2'][$_child2_i]...['varname'] . '
	* It's ready to be inserted into an "echo" line in one of the templates.
	* NOTE: expects a trailing "." on the namespace.
	* @access private
	*/
	function generate_block_varref($namespace, $varname)
	{
		// Strip the trailing period.
		$namespace = substr($namespace, 0, strlen($namespace) - 1);

		// Get a reference to the data block for this namespace.
		$varref = $this->generate_block_data_ref($namespace, true);

		// Prepend the necessary code to stick this in an echo line.
		// Append the variable reference.
		$varref .= '[\'' . $varname . '\']';

		return $varref;
	}

	/**
	* Generates a reference to the array of data values for the given
	* (possibly nested) block namespace. This is a string of the form:
	* $this->_tpldata['parent'][$_parent_i]['$child1'][$_child1_i]['$child2'][$_child2_i]...['$childN']
	*
	* If $include_last_iterator is true, then [$_childN_i] will be appended to the form shown above.
	* NOTE: does not expect a trailing "." on the blockname.
	* @access private
	*/
	function generate_block_data_ref($blockname, $include_last_iterator)
	{
		// Get an array of the blocks involved.
		$blocks = explode('.', $blockname);
		$blockcount = sizeof($blocks) - 1;
		$varref = '$this->_tpldata';

		// Build up the string with everything but the last child.
		for ( $i = 0; $i < $blockcount; $i++ )
		{
			$varref .= '[\'' . $blocks[$i] . '.\'][$_' . $blocks[$i] . '_i]';
		}

		// Add the block reference for the last child.
		$varref .= '[\'' . $blocks[$blockcount] . '.\']';

		// Add the iterator for the last child if requried.
		if ( $include_last_iterator )
		{
			$varref .= '[$_' . $blocks[$blockcount] . '_i]';
		}

		return $varref;
	}
}

?>