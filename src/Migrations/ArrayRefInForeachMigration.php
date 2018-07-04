<?hh // strict
/**
 * Copyright (c) 2016, Facebook, Inc.
 * All rights reserved.
 *
 * This source code is licensed under the BSD-style license found in the
 * LICENSE file in the root directory of this source tree. An additional
 * grant of patent rights can be found in the PATENTS file in the same
 * directory.
 *
 */

namespace Facebook\HHAST\Migrations;

use namespace \Facebook\HHAST;
use namespace \HH\Lib\{C, Str, Vec};
use namespace \Facebook\TypeAssert;

final class ArrayRefInForeachMigration extends StepBasedMigration {
  private static function makeNullableFieldsOptional(
    HHAST\ForeachStatement $foreach,
  ): HHAST\ForeachStatement {

    # Filter to just expressions that are like `foreach ($foo as &$bar)`
    if (!$foreach->hasCollection() || !$foreach->hasValue()){
      return $foreach;
    }

    $foreach_collection = $foreach->getCollection();
    $foreach_value = $foreach->getValue();
    $foreach_body = $foreach->getBody();

    # Skip over stuff like `foreach ($foo as $key => $value)`
    if ($foreach->hasKey()){
      return $foreach;
    }

    # Filter to ensure the expression is like:
    # `foreach ($foo as &$bar)` or `foreach ($foo['b'] as &$bar)`
    if (!$foreach_value instanceof HHAST\PrefixUnaryExpression || !$foreach_value->getOperator() instanceof HHAST\AmpersandToken){
      return $foreach;
    }

    if (!$foreach_collection instanceof HHAST\VariableExpression && !$foreach_collection instanceof HHAST\SubscriptExpression){
      return $foreach;
    }

    $original_operand = $foreach_value->getOperand();
    if (!$original_operand instanceof HHAST\VariableExpression){
      return $foreach;
    }

    # At this point we know we're looking at something like: foreach ($foo as &$bar).
    #
    # Our strategy is to:
    # 1. Change `foreach ($foo as &$bar)` to `foreach (array_keys($foo) as $foo_key))`.
    # 2. Update references in the foreach body to `$bar` to `$foo[$foo_key]`
    #
    # Risks with this approach:
    # - $bar may have been used after the foreach statement and we're changing its value
    # - $foo_key may have been used after the foreach statement and we're changing its value

    $foreach_collection_ft = $foreach_collection->getFirstTokenx();
    $foreach_collection_lt = $foreach_collection->getLastTokenx();

    $trimmed_collection = $foreach_collection;
    $trimmed_collection = $trimmed_collection->replace($foreach_collection_ft, $foreach_collection_ft->withLeading(HHAST\Missing()));
    $trimmed_collection = $trimmed_collection->replace($foreach_collection_lt, $foreach_collection_lt->withTrailing(HHAST\Missing()));

    # First reformant code in the foreach statement itself
    $array_key_variable = self::makeNewOperand($original_operand);

    $foreach = $foreach->replace($foreach_collection, self::makeArrayValuesCollection($foreach_collection));
    $foreach = $foreach->replace($foreach_value, $array_key_variable);

    # Next reformat code in the foreach body
    $original_operand_token = $original_operand->getFirstTokenx();
    $array_variable_references = $foreach_body->getDescendantsWhere((HHAST\EditableNode $node, vec<HHAST\EditableNode> $parents): bool ==> {
      if (!$node instanceof HHAST\VariableExpression) return false;

      $variable_token = $node->getFirstTokenx();

      return $variable_token->getText() == $original_operand_token->getText();
    });


    foreach ($array_variable_references as $reference){
      assert($reference instanceof HHAST\VariableExpression);

      $replacement = self::makeArraySubscriptExpression($reference, $trimmed_collection, $array_key_variable);
      $foreach = $foreach->replace($reference, $replacement);
    }

    return $foreach;
  }

  private static function makeNewOperand(HHAST\VariableExpression $original): HHAST\VariableExpression {
    # Hopefully no one is doing anything crazy with multiple variables in their loop operands (not sure what
    # this would be anyways)
    $original_token = $original->getFirstTokenx();

    return new HHAST\VariableExpression(new HHAST\VariableToken(
      $original_token->getLeading(),
      $original_token->getTrailing(),
      $original_token->getText() . '_key',
    ));
  }

  private static function makeArrayValuesCollection(HHAST\EditableNode $original_collection): HHAST\FunctionCallExpression {
    $first_token = $original_collection->getFirstTokenx();
    $last_token = $original_collection->getLastTokenx();

    $leading_whitespace = $first_token->getLeading();
    $original_collection = $original_collection->replace($leading_whitespace, HHAST\Missing());

    $trailing_whitespace = $last_token->getTrailing();
    $original_collection = $original_collection->replace($trailing_whitespace, HHAST\Missing());

    return new HHAST\FunctionCallExpression(
      new HHAST\NameToken($leading_whitespace, HHAST\Missing(), 'array_keys'),
      new HHAST\LeftParenToken(HHAST\Missing(), HHAST\Missing()),
      $original_collection,
      new HHAST\RightParenToken(HHAST\Missing(), $trailing_whitespace),
    );
  }

  #
  # Take a SubscriptExpression or VariableExpression that repesents a reference to the collection
  # in the foreach and generate a replacement for it. So `$value` becomes `$values[$value_key]`
  # or `$value` becomes `$ret['values'][$value_key]`
  #
  # $original - `$value`
  # $collection - the collection being iterated over by reference eg. `$ret['values']` or `$values`
  # $collectin_key - the key variable used to index into the collection eg. `$value_key`
  #
  private static function makeArraySubscriptExpression(HHAST\VariableExpression $original, HHAST\EditableNode $collection, HHAST\VariableExpression $array_key): HHAST\SubscriptExpression {
    $original_last_token = $original->getFirstTokenx();
    $original_first_token = $original->getLastTokenx();

    $leading_collection_token = $collection->getFirstTokenx();
    $whitespaced_collection_token = $leading_collection_token->withLeading($original_first_token->getLeading());

    $padded_collection = $collection->replace($leading_collection_token, $whitespaced_collection_token);

    return new HHAST\SubscriptExpression(
      $padded_collection,
      new HHAST\LeftBracketToken(HHAST\Missing(), HHAST\Missing()),
      $array_key,
      new HHAST\RightBracketToken(HHAST\Missing(), $original_last_token->getTrailing()),
    );
  }

  <<__Override>>
  final public function getSteps(
  ): Traversable<IMigrationStep> {
    return vec[
      new TypedMigrationStep(
        'repair array refs in foreach loops',
        HHAST\ForeachStatement::class,
        HHAST\ForeachStatement::class,
        $foreach ==> self::makeNullableFieldsOptional($foreach),
      ),
    ];
  }
}