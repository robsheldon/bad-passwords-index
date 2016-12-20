<?php

	/*

		Create a bad password index file:

		Read in a list of passwords (one to a line) and spit out a concatenated string containing all of the passwords,
		lowercased, roughly in order of popularity.

	*/

	//	Tunable parameters.
	//	maxlen: the maximum expected length of any password in the input set.
	$maxlen = 100;

	//	delimiter: the field delimiter used to separate password strings in $search_space.
	//	This should be a character you're certain not to find in a password.
	$delimiter = "\n";

	//	ouput_len: the total number of bytes to output.
	$output_len = 1048576;

	//	max_lookahead: the maximum number of passwords to skip in the output
	//	when trying to concatenate passwords together.
	$max_lookahead = 300;

	//	lookahead_chars: the maximum number of characters to look at when trying to concatenate
	//	passwords together during the write phase.
	$lookahead_chars = 9;

	//	count_threshold: ignore passwords with fewer than this many occurrences in the input file.
	//	This will usually result in a more efficient output file (and gives you a huge speed increase).
	//	Think of it this way: each character in the output file will be worth at least this many passwords.
	$count_threshold = 4;

	//	Debugging.
	$debug_matches = false;	//	Double-check the password matching for every match.


	//	Counters.
	$in_count = 0;			//	Total number of input passwords.
	$in_unique = 0;			//	Unique input passwords.
	$in_threshold = 0;		//	Number of unique input passwords that met or exceeded $count_threshold.
	$merge_count = 0;		//	Number of password matches made during merge phase.
	$out_count = 0;			//	Total count of passwords covered by the output file.
	$out_unique = 0;		//	Number of unique passwords in the output file.
	$out_concatenated = 0;	//	Number of passwords that were concatenated together in the output file.
	$concat_bytes = 0;		//	Number of bytes that were saved by output concatenation.
	$last_out_count = 0;	//	Number of times the last password written to the output file occurred in the input file(s).
	$best_concat = array();
	$best_concat_count = 0;


	$substring_bugs = 0;



	if ( $argc < 3 ) exit('Usage: ' . __FILE__ . "<outfile> <infile> [infile...]\n\n");

	$arguments = $argv;
	array_shift($arguments);

	$out_arg = array_shift($arguments);
	//	Attempt to open the output file for writing immediately, in case there's
	//	an error, don't waste time reading everything in only to barf.
	$outfile = fopen($out_arg, 'w');
	if ( $outfile === false ) exit("Error attempting to open $out_arg for writing.");

	$grouped_passwords = array();
	for ( $i = $maxlen - 1; $i; $i-- ) $grouped_passwords[] = array();

	while ($arguments) {
		$filename = array_shift($arguments);
		echo "Reading $filename...\n";
		$lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ( $lines === false ) { exit("Error opening input file $filename.\n\n"); }
		//	The other approach here looks like:
		//		$lines = array_filter(array_map(function($element){return strtolower(trim($element));}, $lines), 'strlen');
		//		$in_count += count($lines);
		//		$passwords = array_count_values($lines);
		//	...etc., but not only is this _slower_ than hand-rolling it, but PHP gags on it and consumes
		//	all available system memory. Because PHP.
		foreach ($lines as $line) {
			$line = strtolower(trim($line));
			//	Passwords are grouped by password length so that later on
			//	the passwords are processed from longest to shortest, and
			//	each password in a group is checked against the previous
			//	longest passwords, without having to check on any passwords
			//	of the same length.
			//	During the merge process, unmatched passwords are merged into
			//	the master password set and the next group of passwords get
			//	compared against them.
			$len = mb_strlen($line);
			if ( ! $len ) continue;
			$in_count++;
			if ( ! isset($grouped_passwords[$len][$line]) ) {
				$grouped_passwords[$len][(string)$line] = 1;
				//	Update the number of unique passwords in the input.
				$in_unique++;
			} else {
				$grouped_passwords[$len][(string)$line]++;
			}
		}
	}

	if ( $count_threshold > 1 ) {
		echo "Pruning...\n";
		//	Rule #1 of optimization: be lazy, do as little work as possible.
		foreach ($grouped_passwords as $len => &$group) {
			//	The next line iterates over each cluster of passwords in this
			//	group and removes the cluster if there are not more than
			//	$count_threshold elements in the cluster.
			$group = array_filter($group, function ($count) use ($count_threshold) {return $count >= $count_threshold;});
			//	If the entire group got deleted, then remove it from $grouped_passwords.
			if ( count($group) < 1 ) unset($grouped_passwords[$len]);
		}
	}

	echo "Sorting...\n";
	foreach ($grouped_passwords as $len => &$group) {
		//	Update the number of passwords over the $count_threshold tunable.
		$in_threshold += count($group);
		//	And sort the group from most common to least common.
		//	This step isn't strictly neccessary, but it does seem to improve
		//	the packing performance later on and doesn't noticeably affect running
		//	time.
		arsort($group);
	}

	echo "Merging...\n";
	//	Grab the first set of longest passwords to initialize the search process.
	$merged_passwords = array_pop($grouped_passwords);
	//	$delimiter is added to the beginning and end of $search_space so that the substr
	//	search block below doesn't have to do additional checks for overruns/underruns.
	//	$search_space is just a gigantic block of delimited text that's used to locate
	//	matching array keys without having to do a substring comparison on each array
	//	key.
	$search_space = $delimiter . implode($delimiter, array_keys($merged_passwords)) . $delimiter;
	//	Now begin the search & merge loop.
	while ( count($grouped_passwords) ) {
		$group = array_pop($grouped_passwords);
		foreach ($group as $password => $count) {
			//	FUCKING PHP. From the docs for strpos():
			//	"If needle is not a string, it is converted to an integer and applied as the ordinal value of a character."
			//	What it actually means is, "If needle IS NUMERIC", because PHP is matching "4753474" etc. to "password".
			//	settype() here fixes the REALLY FUCKING BROKEN strpos() behavior.
			//	DAMMIT, this cost me hours to figure out. I chased my ass forever thinking I boned the string
			//	searching function somehow.
			settype($password, 'string');				//	HATE. FURY. RAGE.
			if ( ($i = strpos($search_space, $password)) == false ) {
				//	Add the unmatched password into $merged_passwords now.
				$merged_passwords[$password] = $count;
				continue;
			}
			//	Locate the matching password and add the counts.
			$j = $i + strlen($password);
			//	Look forward and backward for $delimiter.
			while ( $search_space[$i-1] != $delimiter ) $i--;
			$j += strcspn($search_space, $delimiter, $j);
			$matching_password = substr($search_space, $i, $j - $i);
			echo $password . ' -> ' . $matching_password . "\n";
			$merged_passwords[$matching_password] += $count;
			$merge_count++;
		}
		//	Re-sort by count and generate a new $search_space.
		//	(Continuous re-sorting ensures that the most popular password variations
		//	keep bubbling up to the top of the output.)
		arsort($merged_passwords);
		$search_space = $delimiter . implode($delimiter, array_keys($merged_passwords)) . $delimiter;
	}

	//	Grab the top ten passwords and their counts.
	$top_ten = array_slice($merged_passwords, 0, 10);

	//	Reverse the array to use array_pop().
	$merged_passwords = array_reverse($merged_passwords);

	//	Now start writing to the output file.
	echo "Writing...\n";
	//	Leave room for the date stamp at the end of the file.
	$output_len -= 10;

	//	Initialize the output file with the most popular password.
	$out_count = end($merged_passwords);
	$last_password = key($merged_passwords);
	$outbytes = fwrite($outfile, $last_password);
	array_pop($merged_passwords);
	$lookahead = $max_lookahead;

	$batch = array_splice($merged_passwords, 0 - $max_lookahead);

	while ( count($batch) ) {
		//	Begin writing and concatenating passwords.
		$i = 0;
		reset($batch);
		//	$search starts out as the last $lookahead_chars characters of the last-written password.
		$search = strlen($last_password) > $lookahead_chars ? substr($last_password, strlen($last_password) - $lookahead_chars) : $last_password;
		while ( list($password, $count) = each($batch) ) {
			//	Check the current password against the search.
			if ( substr($password, 0, strlen($search)) == $search ) {
				//	Yay, a match.
				//	Trim the current $search from the beginning of $password.
				unset($batch[$password]);
				//	Update best concat statistic.
				if ( strlen($search) > $best_concat_count ) {
					$best_concat = array($last_password, $password);
					$best_concat_count = strlen($search);
				}
				$password = substr($password, strlen($search));
				//	Reduce the lookahead for the next search.
				$lookahead = $lookahead == 1 ? $max_lookahead : intval($lookahead / 2);
				//	Update statistics.
				$concat_bytes += strlen($search);
				$out_concatenated++;
				//	And break out to the output section.
				break;
			} else if ( ++$i > $lookahead || $i >= count($batch) ) {
				if ( strlen($search) > 1 ) {
					//	No matches for the current search, so trim it and
					//	try again.
					$search = substr($search, 1);
					reset($batch);
					$i = 0;
				} else {
					//	No matches at all in the current lookahead space.
					//	Reset the lookahead and $password/$count, so that
					//	the most popular password in the current batch will
					//	get written next.
					$count = reset($batch);
					$password = key($batch);
					unset($batch[$password]);
					$lookahead = $max_lookahead;
					break;
				}
			}
		}
		//	Check password length against remaining available file size.
		if ( strlen($password) + $outbytes > $output_len ) {
			echo "File complete\n";
			//	End condition. Skip this password, look for any passwords
			//	that will fit, then write a partial password if necessary,
			//	then end the output loop.
			$passwords = array_reverse(array_keys($merged_passwords));
			$candidate = '';
			$n = $output_len - $outbytes;
			for ( $i = 0; $i < count($passwords) && $candidate = ''; $i++ ) {
				if ( strlen($passwords[$i]) == $n ) {
					$candidate = $passwords[$i];
					settype($candidate, 'string');
				}
			}
			if ( $candidate != '' ) {
				//	Cool, write this password to the file and update the counters.
				fwrite($outfile, $candidate);
				$out_count += $merged_passwords[$candidate];
				$out_unique++;
			} else {
				//	No perfect fits found.
				//	Write a partial remaining password to the file.
				fwrite($outfile, substr($password, 0, $output_len - $outbytes));
			}
			break;
		} else {
			if ( strlen($password) < 1 ) {
				//	$search matched the entirety of $password -- a rare edge case, but happens.
				//	For instance, "020478" is written to the output file, then "020478" is
				//	searched for if $lookahead_chars = 6, and "20478" is also in the current
				//	batch. This causes an annoying glitch.
				//	Just ignore this for now and continue.
				//	TODO: This shouldn't be happening and indicates a bug in the substring
				//	checking code. Argh.
				$substring_bugs++;
			} else {
				echo "Writing: ${password}\n";
				//	Write the current $password and $count here.
				$outbytes += fwrite($outfile, $password);
				$last_password = $password;
			}
			$out_count += $count;
			$last_out_count = $count;
			$out_unique++;
		}
		//	And add the next password to the batch.
		if ( count($merged_passwords) ) {
			//	array_splice() is significantly slower than this
			//	approach, and appears to give screwy results besides.
			$count = end($merged_passwords);
			$password = key($merged_passwords);
			array_pop($merged_passwords);
			settype($password, 'string');
			$batch[$password] = $count;
		} else {
			echo "Ran out of passwords to match!\n";
		}
		//	And loop back.
	}

	//	Write today's date to the end of the file.
	fwrite($outfile, '//' . date('Ymd'));
	fclose($outfile);

	//	Generate some statistics.
	echo "in_count: ${in_count}\n";
	echo "in_unique: ${in_unique}\n";
	echo "in_threshold: ${in_threshold}\n";
	echo "merge_count: ${merge_count}\n";
	echo "out_count: ${out_count}\n";
	echo "out_unique: ${out_unique}\n";
	echo "out_concatenated: ${out_concatenated}\n";
	echo "concat_bytes: ${concat_bytes}\n";
	echo "last_out_count: ${last_out_count}\n";
	echo "best_concat_count: ${best_concat_count}\n";
	echo "best_concat: \"" . implode('", "', $best_concat) . "\"\n";

	echo "\nDone.\n\n";

?>