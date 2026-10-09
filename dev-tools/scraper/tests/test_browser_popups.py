from mma_future_scraper.browser import dismiss_blocking_ui


class _FakeLocator:
    def __init__(self, *, visible=False, on_click=None):
        self.visible = visible
        self.on_click = on_click
        self.first = self

    def count(self):
        return 1 if self.visible else 0

    def is_visible(self, timeout=None):
        return self.visible

    def click(self, timeout=None):
        if self.on_click:
            self.on_click()


class _FakeKeyboard:
    def __init__(self, page):
        self.page = page

    def press(self, key):
        self.page.keys_pressed.append(key)


class _FakePopupPage:
    def __init__(self, *, role_label=None, selector=None):
        self.role_label = role_label
        self.selector = selector
        self.clicked = []
        self.keys_pressed = []
        self.keyboard = _FakeKeyboard(self)

    def get_by_role(self, role, name, exact=False):
        visible = role == "button" and self.role_label and self.role_label.lower() in name.lower()
        return _FakeLocator(visible=visible, on_click=lambda: self.clicked.append(f"role:{name}"))

    def locator(self, selector):
        return _FakeLocator(
            visible=selector == self.selector,
            on_click=lambda: self.clicked.append(f"selector:{selector}"),
        )

    def wait_for_timeout(self, *_args, **_kwargs):
        return None


def test_dismiss_blocking_ui_clicks_named_close_button():
    page = _FakePopupPage(role_label="Close")
    summary = {}

    dismiss_blocking_ui(page, lambda *_args, **_kwargs: None, summary)

    assert page.clicked == ["role:Close"]
    assert summary["popup_dismissals_count"] == 1
    assert "popup button:Close" in summary["selectors_used"]


def test_dismiss_blocking_ui_clicks_aria_close_selector():
    page = _FakePopupPage(selector="button[aria-label*='close' i]")
    summary = {}

    dismiss_blocking_ui(page, lambda *_args, **_kwargs: None, summary)

    assert page.clicked == ["selector:button[aria-label*='close' i]"]
    assert summary["popup_dismissals_count"] == 1
    assert "popup close:button[aria-label*='close' i]" in summary["selectors_used"]


def test_dismiss_blocking_ui_clicks_tapology_ad_position_dismiss_button():
    page = _FakePopupPage(selector="#ad_position_box #dismiss-button-element")
    summary = {}

    dismiss_blocking_ui(page, lambda *_args, **_kwargs: None, summary)

    assert page.clicked == ["selector:#ad_position_box #dismiss-button-element"]
    assert summary["popup_dismissals_count"] == 1
    assert "popup close:#ad_position_box #dismiss-button-element" in summary["selectors_used"]


def test_dismiss_blocking_ui_uses_escape_when_no_control_visible():
    page = _FakePopupPage()
    summary = {}

    dismissed = dismiss_blocking_ui(page, lambda *_args, **_kwargs: None, summary)

    assert dismissed == 0
    assert page.clicked == []
    assert page.keys_pressed == ["Escape"]
    assert "popup escape" in summary["selectors_used"]


def test_dismiss_blocking_ui_multiple_rounds_is_noop_when_no_popup_exists():
    page = _FakePopupPage()
    summary = {}

    dismissed = dismiss_blocking_ui(page, lambda *_args, **_kwargs: None, summary, max_rounds=3)

    assert dismissed == 0
    assert page.clicked == []
    assert summary.get("popup_dismissals_count") is None
