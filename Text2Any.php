<?php

/*
 * Text parser, text to any (folders, sections etc.) 
 * Examle:
 * config:access=755;document_root=self/test
 * Parent Folder
 * 	Subfolder
 * 		SubSubFolder
 * New folder:access=555
 * shared:access=777 
 * 
 * Use (create folders):
 * $p = new CText2AnyParser();
 * $p->load($_SERVER['DOCUMENT_ROOT'] . '/text.txt')->parse()->run()->checkError();
 */

/**
* Text parser class
* @author Slashinin Andrey (slashinin.andrey@gmail.com)
*/
class CText2AnyParser
{
	var $arSections = array();
	var $arConfig = array();
	var $separator;
	var $text;
	var $arText = array();
	var $parentId;
	var $lastId;
	var $lastDepth;
	var $depth;

	function CText2AnyParser()
	{
		/* Initialization defaults */
		$this->separator = "	";
		$this->text = '';
		$this->parentId = $this->lastId = 0;
		$this->lastDepth = $this->depth = 0;
	}

	function load($text)
	{
		if (is_file($text)) {
			$text = file_get_contents($text);
		}

		$this->text = trim($text);
		$this->arText = explode("\n", $this->text);
		$this->config();
		return $this;
	}

	function config()
	{
		$config = $this->arText[0];

		if (substr_count($config, 'config:') == 1) {
			$config = str_replace('config:', '', $config);

			unset($this->arText[0]);

			$this->arConfig = $this->parseConfig($config);
		}

		return $this;
	}

	function parse($addClass = 'CText2AnyFolderRunner')
	{
		if ($this->text == '') {
			return false;
		}

		$this->config();

		foreach ($this->arText as $id => $line) {
			$line = rtrim($line);
			$this->arSections[$id] = $this->parseLine($line,$id);
		}

		if (class_exists($addClass)) {
			$r = new $addClass;
			$r->sections = $this->arSections;
			$r->config = $this->arConfig;
			return $r;
		} 

		return new CText2AnyBaseRunner(); 		
	}

	function parseLine($line,$id)
	{
		$params = $this->parseNode($line);
		$this->depth = substr_count($line, $this->separator);

		if ($this->depth > 0) {			
			if ($this->depth > $this->lastDepth) {
				$this->parentId = $this->lastId;
			}
		} else {
			$this->parentId = 0;
		}

		$r = array(
			'name' => trim($params['name']),
			'id' => intval($id),
			'parentId' => intval($this->parentId),
			'config' => (array)$params['params'],
			'depth' => intval($this->depth),
		);

		$this->lastId = $id;
		$this->lastDepth = $this->depth;
		return $r;
	}

	function parseNode($line)
	{
		$result = array(
			'name' => '',
			'params' => array()
		);

		if (substr_count($line, ':') == 0) {
			$result['name'] = trim($line);
			return $result;
		}
		
		list($name,$config) = explode(':', $line);

		$result['name'] = $name;
		$result['params'] = $this->parseConfig($config);

		return $result;
	}

	function parseConfig($line)
	{
		$params = explode(';', $line);
		$result = array();
		foreach ($params as $key => $value) {
			if (substr_count($value, '=') == 0) {
				continue;
			}

			list($left,$right) = explode('=', $value);
			$result[$left] = $right;
		}

		return $result;
	}
}


class CText2AnyBaseRunner {
	var $errors;
	var $sections;
	var $config;

	function CText2AnyBaseRunner()
	{
		$this->config = array();
		$this->errors = array();
		$this->sections = array();
	}

	function init()
	{
		return $this;
	}

	function run() 
	{		
		echo 'Class not found!';
		return $this;
	}

	function setError($error)
	{
		$this->errors[] = $error;
	}

	function getConfig($key,$default = '',$param=false)
	{
		if (isset($this->config[$key])) {
			$default = $this->config[$key];
		} 

		if (is_array($param) && count($param) > 0) {
			$default = strtr($default, $param);
		}

		if (is_numeric($param)) {
			if (isset($this->sections[$param]['config'][$key])) {
				$default = trim($this->sections[$param]['config'][$key]);
			}
		}

		return trim($default);
	}

	function hasError()
	{
		return count((array)$this->errors) > 0;
	}

	function checkError()
	{
		if ($this->hasError()) {
			echo '<pre>';
			print_r($this->errors);
			echo '</pre>';
		}
	}
}

class CText2AnyFolderRunner extends CText2AnyBaseRunner {
	function run()
	{
		$dir = $this->getConfig('document_root',false,array(
			'self' => $_SERVER['DOCUMENT_ROOT']
		));

		if ($dir === false || !is_dir($dir)) {
			$this->setError('Wrong document root "'.$dir.'".');
			return $this;
		}

		foreach ($this->sections as $id => $param) {
			
			$folderName = trim($param['name']);
			$parentId = intval($param['parentId']);

			if ($parentId > 0) {
				$folderName = $this->sections[$parentId]['src'].'/'.$folderName;
			}

			$this->sections[$id]['src'] = $param['src'] = $folderName;
			$folderPath = $dir .'/'. $folderName;

			if (is_dir($folderPath)) {
				$this->setError('Directory exists "'.$folderName.'"');
				continue;		
			}

			$access = $this->getConfig('access','775',$id);

			if (strlen($access) == '3') {
				$access = '0'.$access;
			}

			if (@mkdir($folderPath)) {
				chmod($folderPath, octdec($access));
			} else {
				$this->setError('Error while creating directory "'.$folderName.'"');
				continue;
			}
		}

		return $this;
	}
}