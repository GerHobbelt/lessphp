#!/usr/bin/env php
<?php
error_reporting(E_ALL);

/**
 * Go through all files matching pattern in input directory
 * and compile them, then compare them to paired file in
 * output directory.
 */
$difftool = 'meld';

// which directories to scan for tests...
$inputs = array(
array(
	'dir' => 'inputs',
	'glob' => '*.less',
	'importDir' => '%s/test-imports'
),
array(
	'dir' => 'less.js/less',
	'glob' => '*.less',
	'importDir' => '%s/import'
),
// and output should map onto itself:
array(
	'dir' => 'outputs',
	'glob' => '*.css',
	'importDir' => '%s'
)
);

// one outputs[] entry per $inputs[] entry...
$outputs = array(
array(
	'dir' => 'outputs',
	'filename' => '%s.css'
),
array(
	'dir' => 'less.js/css',
	'filename' => '%s.css'
),
array(
	'dir' => 'outputs',
	'filename' => '%s.css'
)
);


$prefix = strtr(realpath(dirname(__FILE__)), '\\', '/');
require $prefix.'/../lessc.inc.php';


$fa = 'Fatal Error: ';
if (php_sapi_name() != 'cli') { 
	exit($fa.$argv[0].' must be run in the command line.');
}

$exe = array_shift($argv); // remove filename
function flag($f) {
	if (func_num_args() > 1) {
		foreach (func_get_args() as $f) if (flag($f)) return true;
		return false;
	}
	global $argv;
	$pre = strlen($f) > 1 ? '--' : '-';
	foreach ($argv as $a) {
		if (preg_match('/^'.$pre.$f.'($|\s)/', $a)) return true;
	}
	return false;
}

if (flag('h', 'help')) {
	exit('help me');
}
if (flag('unix-diff')) {
	$difftool = 'diff -b -B -t -u';
}

// get the first non flag as search string
$searchString = null;
foreach ($argv as $a) {
	if (strlen($a) > 0 && $a{0} != '-') {
		$searchString = $a;
		break;
	}
}

$tests = array();
for ($i = 0; $i < count($inputs); $i++)
{
	// clone arrays:
	$input = array_merge(array(), $inputs[$i]);
	$output = array_merge(array(), $outputs[$i]);
	
	$input['dir'] = $prefix.'/'.$input['dir'];
	$output['dir'] = $prefix.'/'.$output['dir'];
	if (!is_dir($input['dir']) || !is_dir($output['dir']))
		exit($fa." both input and output directories must exist: '{$input['dir']}' and '{$output['dir']}'\n");

	$matches = glob($input['dir'].'/'.(!is_null($searchString) ? '*'.$searchString : '' ).$input['glob']);
	if ($matches) {
		foreach ($matches as $fname) {
			extract(pathinfo($fname)); // for $filename, from php 5.2
			$tests[] = array(
				'in' => $fname,
				'out' => $output['dir'].'/'.sprintf($output['filename'], $filename), 
				'importDir' => array(sprintf($input['importDir'], $input['dir']), $input['dir'])
			);
		}
	}
}

$count = count($tests);
$compiling = flag('C');
$showDiff = flag('d', 'diff');
echo ($compiling ? "Compiling" : "Running")." $count test".($count == 1 ? '' : 's').":\n";

function dump($msgs, $depth = 1, $prefix="    ") {
	if (!is_array($msgs)) $msgs = array($msgs);
	foreach ($msgs as $m) {
		echo str_repeat($prefix, $depth).' - '.$m."\n";
	}
}

$fail_prefix = " ** ";

$i = 1;
$fail_count = 0;
foreach ($tests as $test) {
	printf("    [Test %04d/%04d] %s -> %s\n", $i, $count, basename($test['in']), basename($test['out']));

	$compiler = new lessc();
	/*
	  the last dir in the importDir array is also used as 'current dir' of 
	  any string data fed to the compiler, i.e. any stuff that doesn't 
	  come with a filename itself.
	  
	  The way this is written is not advisable to copycat; use
		  $compiler = new lessc($test['in']);
		  $parsed = trim($compiler->parse();
	  instead, but then you'ld loose the 'inputs/test-imports' importDir
	  setup here; it is a hack (IMO) for previously incorrect path behaviour
	  of lessphp where some tests have the incorrect
		  @import('file1.less');
	  rather than the correct
		  @import('test-imports/file1.less');
	 */
	$compiler->importDir = $test['importDir'];
	
	try {
		ob_start();
		$parsed = trim($compiler->parse(file_get_contents($test['in'])));
		ob_end_clean();
	} catch (Exception $e) {
		dump(array(
			"Failed to compile input, reason:",
			$e->getMessage(),
			"Aborting"
		), 1, $fail_prefix);
		//break;
	}

	if ($compiling) {
		file_put_contents($test['out'], $parsed);
	} else {
		if (!is_file($test['out'])) {
			dump(array(
				"Failed to find output file: $test[out]",
				"Maybe you forgot to compile tests?",
				"Aborting"
			), 1, $fail_prefix);
			break;
		}
		$expected = trim(file_get_contents($test['out']));

		try {
			ob_start();
			$compiler = new lessc($test['out']);
			$expected_alt = trim($compiler->parse());
			ob_end_clean();
		} catch (Exception $e) {
			$expected_alt = $expected;
		}
		
		// don't care about CRLF vs LF change (DOS/Win vs. UNIX):
		$expected = trim(str_replace("\r\n", "\n", $expected));
		$expected_alt = trim(str_replace("\r\n", "\n", $expected_alt));
		$parsed = trim(str_replace("\r\n", "\n", $parsed));

		if ($expected != $parsed && $expected_alt != $parsed) {
			$fail_count++;
			if ($showDiff) {
				dump("Failed:", 1, $fail_prefix);
				$tmpinp = $test['out'].".in.tmp";
				$tmpout = $test['out'].".out.tmp";
				@unlink($tmpinp);
				@unlink($tmpout);
				file_put_contents($tmpinp, $parsed);
				file_put_contents($tmpout, $expected_alt);
				print("----------------------------------------------------------------------------\n");
				system($difftool.' '.$tmpout.' '.$tmpinp);
				print("----------------------------------------------------------------------------\n");
				@unlink($tmpinp);
				@unlink($tmpout);

				//dump("Aborting");
				//break;
			} else {
				dump("Failed, run with -d flag to view diff", 1, $fail_prefix);
			}
		} else {
			dump("Passed");
		}
	}

	$i++;
}
$i--;

printf("    [Tests: %d / failed: %d / passed: %d]\n", $i, $fail_count, $i - $fail_count);

exit($fail_count? 1 : 0);

?>
