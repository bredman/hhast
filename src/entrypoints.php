<?hh // strict
/*
 *  Copyright (c) 2017-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the MIT license found in the
 *  LICENSE file in the root directory of this source tree.
 *
 */

namespace Facebook\HHAST;

use namespace HH\Lib\Keyset;

function from_json(
  dict<string, mixed> $json,
  ?string $file = null,
): EditableNode {
  return EditableNode::fromJSON(
    /* HH_IGNORE_ERROR[4110] */ $json['parse_tree'],
    $file ?? '! no file !',
    0,
    /* HH_IGNORE_ERROR[4110] */ $json['program_text'],
  );
}

async function json_from_file_async(string $file): Awaitable<dict<string, mixed>> {
  try {
    using (await __Private\ParserConcurrencyLease::getAsync()) {
      $results = await __Private\execute_async(
        'hh_parse',
        '--php5-compat-mode',
        '--full-fidelity-json',
        $file,
      );
    }
  } catch (__Private\SubprocessException $e) {
    throw new HHParseError(
      $file,
      'hh_parse failed - exit code: '.$e->getExitCode(),
    );
  }
  $json = $results[0];

  $encodings = keyset(\mb_list_encodings());
  unset($encodings['pass']);
  unset($encodings['auto']);
  $encodings = Keyset\union(keyset['UTF-8'], $encodings);
  $encoding = \mb_detect_encoding(\file_get_contents($file), $encodings, /* strict = */ true);
  if ($encoding !== false && $encoding !== 'UTF-8') {
    $json = @\iconv($encoding, 'UTF-8', $json);
  }

  $json = \json_decode(
    $json,
    /* as array = */ true,
    /* depth = */ 512 /* == default */,
    \JSON_FB_HACK_ARRAYS,
  );
  $no_type_refinement_please = $json;
  if (!is_dict($no_type_refinement_please)) {
    throw new HHParseError($file, 'hh_parse did not output valid JSON');
  }
  return $json;
}

function json_from_file(string $file): dict<string, mixed> {
  return \HH\Asio\join(json_from_file_async($file));
}

async function from_file_async(string $file): Awaitable<EditableNode> {
  $json = await json_from_file_async($file);
  return from_json($json, $file);
}

function from_file(string $file): EditableNode {
  return \HH\Asio\join(from_file_async($file));
}

async function json_from_text_async(string $text): Awaitable<dict<string, mixed>> {
  $file = \tempnam("/tmp", "");
  $handle = \fopen($file, "w");
  \fwrite($handle, $text);
  \fclose($handle);
  $json = await json_from_file_async($file);
  \unlink($file);
  return $json;
}

function json_from_text(string $text): dict<string, mixed> {
  return \HH\Asio\join(json_from_text_async($text));
}

async function from_code_async(string $text): Awaitable<EditableNode> {
  $json = await json_from_text_async($text);
  return from_json($json);
}

function from_code(string $text): EditableNode {
  return \HH\Asio\join(from_code_async($text));
}
