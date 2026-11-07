<?php
declare(strict_types=1);
namespace AISkillsHub\Core\Discovery;
defined( 'ABSPATH' ) || exit;

/** UI copy is translated; resource and editorial translations are never synthesized. */
final class Strings {
	private const TEXT = array(
		'discover' => array( '发现 Skills', 'Discover Skills' ),
		'trending' => array( '趋势 Skills', 'Trending Skills' ),
		'most-viewed' => array( '最多浏览', 'Most Viewed' ),
		'most-favorited' => array( '最多收藏', 'Most Favorited' ),
		'top-rated' => array( '最高评分', 'Top Rated' ),
		'recently-added' => array( '最近收录', 'Recently Added' ),
		'recently-updated' => array( '最近内容更新', 'Recently Updated' ),
		'editors-picks' => array( '编辑精选', "Editor's Picks" ),
		'collections' => array( '精选集合', 'Collections' ),
		'use-cases' => array( '按使用场景发现', 'Explore by Use Case' ),
		'platforms' => array( '平台专题', 'Platform Collections' ),
		'categories' => array( '探索分类', 'Explore by Category' ),
		'tutorials' => array( '学习指南', 'Learning Guides' ),
		'related' => array( '相关 Skills', 'Related Skills' ),
		'similar' => array( '相似 Skills', 'Similar Skills' ),
		'intro' => array( '按真实使用信号和经过审核的内容发现资源。', 'Discover resources through real usage signals and reviewed content.' ),
		'empty' => array( '暂无符合条件的公开资源', 'No eligible public resources yet' ),
		'empty-help' => array( '可尝试调整筛选，或稍后回来查看。这里不会补入演示资源或推测数据。', 'Try different filters or check back later. Only eligible, published resources appear here.' ),
		'error' => array( '暂时无法加载此内容，请稍后重试。', 'This content could not be loaded. Please try again later.' ),
		'not-found' => array( '此专题不存在或尚未公开。', 'This collection is unavailable or has not been published.' ),
		'filters' => array( '筛选资源', 'Filter resources' ),
		'results' => array( '符合条件的资源', 'Eligible resources' ),
		'view-all' => array( '查看全部', 'View all' ),
		'window' => array( '统计窗口', 'Time window' ),
		'days7' => array( '最近 7 天', 'Last 7 days' ),
		'days30' => array( '最近 30 天', 'Last 30 days' ),
		'all-time' => array( '当前全部有效记录', 'All currently valid records' ),
		'coverage' => array( '浏览记录始于', 'View collection began on' ),
		'no-coverage' => array( '尚无有效浏览记录；不会补造历史数据。', 'No qualifying view history yet; historical views are not fabricated.' ),
		'method.trending' => array( '趋势由窗口内的去重浏览、当前有效收藏和已批准评价分值计算，近期事件权重更高。新资源可从“最近收录”发现。不是 GitHub Stars 排行。', 'Trending combines deduplicated views, valid favorites and approved rating points within the window, with more weight for recent events. Discover new resources in Recently Added. This is not a GitHub Stars ranking.' ),
		'method.most-viewed' => array( '只计算启用统计后、获得同意的有效停留浏览；按访客与资源去重，排除明显机器人、管理员及测试访问。', 'Counts eligible, consented visits after collection was enabled, deduplicated by visitor and resource. Known bots, administrators and test traffic are excluded.' ),
		'method.most-favorited' => array( '使用当前有效收藏关系；同一用户与 Skill 只计一次，取消收藏后不再计数。', 'Uses currently active favorites. Each user–Skill relation counts once; removed favorites no longer count.' ),
		'method.top-rated' => array( '仅统计已批准的公开评价。排序考虑评价数量，样本不足的资源保留真实评分，但不作为成熟排名。', 'Only approved public reviews count. Ranking accounts for sample size; limited samples retain their actual ratings without implying an established rank.' ),
		'method.recently-added' => array( '收录日期来自真实 Skill 创建记录，不等同于首次公开发布时间。', 'Added dates come from actual Skill creation records, and are distinct from first publication dates.' ),
		'method.recently-updated' => array( '仅展示已批准的公开内容变化。采集同步、未审核来源变更、AI 草稿和后台操作不会冒充内容更新。', 'Shows approved changes to public content. Sync activity, unreviewed source changes, AI drafts and administrative actions do not count as content updates.' ),
		'views' => array( '有效浏览', 'eligible views' ),
		'favorites' => array( '有效收藏', 'active favorites' ),
		'reviews' => array( '已批准评价', 'approved reviews' ),
		'no-ratings' => array( '暂无已审核评分', 'No approved ratings yet' ),
		'limited-sample' => array( '样本有限', 'Limited sample' ),
		'added' => array( '收录', 'Added' ),
		'approved-update' => array( '内容更新获批', 'Content update approved' ),
		'translation-missing' => array( '暂无中文翻译，显示原始名称', 'English translation unavailable; original name shown' ),
		'platform-evidence' => array( '查看平台依据', 'View platform evidence' ),
		'previous' => array( '上一页', 'Previous' ),
		'next' => array( '下一页', 'Next' ),
		'pagination' => array( '分页导航', 'Pagination' ),
		'home' => array( '首页', 'Home' ),
	);
	public static function get( string $key, string $locale ): string { return self::TEXT[ $key ][ 'en_US' === $locale ? 1 : 0 ] ?? $key; }
}
