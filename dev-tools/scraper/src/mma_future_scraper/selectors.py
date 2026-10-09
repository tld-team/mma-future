FIGHTCENTER_FORM_ID = "fightCenterRefine"
FIGHTCENTER_EVENTS_SELECTOR = ".fightcenterEvents"
FIGHTCENTER_LOAD_MORE_SELECTOR = "#loadMoreButton, .load-more-button"

PREFERRED_FIGHT_CARD_SELECTORS = [
    "#sectionFightCard",
    "[id*='FightCard']",
    "section:has-text('Fight Card')",
    "div:has-text('Fight Card')",
    "table:has(a[href*='/fightcenter/bouts/'])",
    "ul:has(a[href*='/fightcenter/bouts/'])",
]

EVENT_LINK_SELECTOR = "a[href*='/fightcenter/events/']"
BOUT_LINK_SELECTOR = "a[href*='/fightcenter/bouts/']"
FIGHTER_LINK_SELECTOR = "a[href*='/fightcenter/fighters/']"

FILTER_ALIASES = {
    "promotion": ["all promotions", "all"],
    "promotion_all": ["all promotions", "all"],
    "promotion_regional": ["regional"],
    "schedule_results": ["results", "result"],
    "schedule_upcoming": ["upcoming", "future"],
    "sport": ["mma", "mixed martial arts"],
    "region_all": ["", "-", "all", "any", "region", "all regions"],
}
