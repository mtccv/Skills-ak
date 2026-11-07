<?php
declare(strict_types=1);
/** Isolated XML adapter only; real SQL visibility is covered by discovery integration. */
namespace AISkillsHub\Core\Discovery {
 final class Query {
  public array $rows = array();
  public function sitemapCount( string $kind, string $locale ): int { return count( $this->rows[$kind][$locale] ?? array() ); }
  public function sitemapItems( string $kind, string $locale, int $page, int $perPage ): array { return array_slice( $this->rows[$kind][$locale] ?? array(), ($page-1)*$perPage, $perPage ); }
 }
 final class Frontend {
  public static function url( string $route, string $locale, array $args = array() ): string {
   if ( ! in_array( $route, array('collections','use-cases','platforms'), true ) ) { throw new \RuntimeException('Wrong route mapping'); }
   return \home_url('/'.('zh_CN'===$locale?'zh':'en').'/'.$route.'/'.('platforms'===$route?'':$args['id'].'/').rawurlencode($args['slug']).'/');
  }
 }
}
namespace {
 require __DIR__.'/sitemap.php';
 $count = 0; $GLOBALS['home_path']=''; $GLOBALS['options']['blog_public']='1';
 $query = new \AISkillsHub\Core\Discovery\Query();
 foreach (range(1,401) as $id) { $query->rows['collection']['en_US'][]=array('id'=>$id,'slug'=>'collection-'.$id,'updated_at'=>'2026-11-07 01:02:03'); }
 $query->rows['use_case']['zh_CN'][]=array('id'=>5,'slug'=>'research','updated_at'=>'2026-11-07 02:03:04');
 $query->rows['platform']['en_US'][]=array('id'=>8,'slug'=>'platform-codex');
 $map=new \AISkillsHub\Core\Frontend\Sitemap(new \AISkillsHub\Core\Frontend\PublicCatalog(),new \AISkillsHub\Core\Frontend\Editorial());
 $before=$map->indexEntries(); $map->setDiscovery($query); $entries=$map->indexEntries();
 check(count($entries)===count($before)+5,'Injecting discovery invalidates old index memo and includes every 200-item chunk');
 check(count($map->urlEntries('collections','en_US',1))===200,'Collection first chunk');
 check(count($map->urlEntries('collections','en_US',2))===200,'Collection second chunk');
 check(count($map->urlEntries('collections','en_US',3))===1,'No silent 200-topic truncation');
 $last=$map->urlEntries('collections','en_US',3)[0];
 check($last['loc']==='https://example.test/en/collections/401/collection-401/','Collection canonical stable ID URL');
 check($last['lastmod']==='2026-11-07T01:02:03Z','Persisted topic timestamp used');
 check($map->urlEntries('collections','en_US',4)===array(),'No nonexistent chunk');
 check($map->urlEntries('collections','zh_CN',1)===array(),'No invented collection translation');
 check($map->urlEntries('use_cases','zh_CN',1)[0]['loc']==='https://example.test/zh/use-cases/5/research/','Use case route spelling');
 $platform=$map->urlEntries('platforms','en_US',1)[0];
 check($platform['loc']==='https://example.test/en/platforms/platform-codex/' && !isset($platform['lastmod']),'Platform route has no ID or fabricated lastmod');
 foreach(array('collections','use_cases','platforms') as $kind) {
  $parser=new \AISkillsHub\Core\Frontend\Sitemap(new \AISkillsHub\Core\Frontend\PublicCatalog(),new \AISkillsHub\Core\Frontend\Editorial());
  $_SERVER['REQUEST_URI']='/aish-sitemap-'.$kind.'-en-1.xml';$parser->parse(new \WP());
  check((new \ReflectionProperty($parser,'request'))->getValue($parser)['kind']===$kind,'Discovery XML routes parse');
 }
 $GLOBALS['options']['blog_public']='0';
 check($map->urlEntries('collections','en_US',1)===array() && $map->indexEntries()===array(),'Private site overrides cached discovery index');
 echo "Discovery sitemap: $count isolated adapter assertions passed; no live WordPress/MySQL/plugin claims.\n";
}
