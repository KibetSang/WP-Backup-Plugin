<?php

declare(strict_types=1);

namespace BastionBackup\Tests\Core;

use BastionBackup\Core\SearchReplace;
use PHPUnit\Framework\TestCase;

/** Counts any construction, wake-up or destruction, to prove a crafted value never brings an object to life. */
final class InjectionProbe {
	public static int $events = 0;

	public function __wakeup(): void {
		++self::$events;
	}

	public function __destruct() {
		++self::$events;
	}
}

final class SearchReplaceTest extends TestCase {

	/** @return array<string,string> */
	private function pairs(): array {
		return SearchReplace::expand( array( 'http://old.test' => 'https://new.example.com', '/var/www/old' => '/srv/new' ) );
	}

	public function test_nested_serialized_values_and_plain_objects_are_rewritten_with_correct_lengths(): void {
		$o    = new \stdClass();
		$o->u = 'http://old.test/x';
		$val  = serialize( array( 'url' => 'http://old.test/page', 'nested' => serialize( array( 'p' => '/var/www/old/wp' ) ), 'obj' => $o, 'n' => 5 ) );
		$out  = SearchReplace::run( $val, $this->pairs() );
		$d    = unserialize( (string) $out, array( 'allowed_classes' => array( 'stdClass' ) ) );
		$this->assertSame( 'https://new.example.com/page', $d['url'] );
		$this->assertSame( '/srv/new/wp', unserialize( $d['nested'] )['p'] );
		$this->assertSame( 'https://new.example.com/x', $d['obj']->u );
		$this->assertSame( 5, $d['n'] );
	}

	public function test_json_escaped_urls_are_rewritten(): void {
		$this->assertSame( '{"u":"https:\/\/new.example.com\/a"}', SearchReplace::run( '{"u":"http:\/\/old.test\/a"}', $this->pairs() ) );
	}

	public function test_an_object_of_any_other_class_is_left_untouched_and_never_woken_or_destroyed(): void {
		InjectionProbe::$events = 0;
		$class                  = InjectionProbe::class;
		// Built by hand: no InjectionProbe object exists before the call.
		$val = 'a:2:{s:1:"a";O:' . strlen( $class ) . ':"' . $class . '":0:{}s:1:"u";s:15:"http://old.test";}';
		$out = SearchReplace::run( $val, $this->pairs() );
		gc_collect_cycles();
		$this->assertSame( $val, $out );
		$this->assertSame( 0, InjectionProbe::$events, '__wakeup or __destruct ran' );
	}

	public function test_values_without_the_old_address_are_returned_as_they_are(): void {
		$this->assertSame( 'nothing here', SearchReplace::run( 'nothing here', $this->pairs() ) );
		$this->assertSame( 42, SearchReplace::run( 42, $this->pairs() ) );
	}
}
