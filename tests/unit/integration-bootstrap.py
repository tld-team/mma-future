"""Do not allow WordPress's early exit(0) to masquerade as a passed DB suite."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

BOOTSTRAP = Path(__file__).resolve().parents[1] / 'integration/bootstrap.php'
CI_BOOTSTRAP = Path(__file__).resolve().parents[2] / 'ops/ci-bootstrap.php'


class BootstrapTest(unittest.TestCase):
    def run_bootstrap(self, source):
        with tempfile.TemporaryDirectory() as directory:
            stub = Path(directory) / 'wp.php'
            stub.write_text('<?php\n' + source)
            return subprocess.run(
                [os.environ.get('MMAF_TEST_PHP', 'php'), '-r',
                 "require $argv[1]; echo 'FIXTURES_STARTED';", str(BOOTSTRAP)],
                env=dict(os.environ, MMAF_TEST_BOOTSTRAP=str(stub)),
                capture_output=True, text=True,
            )

    def test_wordpress_early_success_exit_is_failure(self):
        result = self.run_bootstrap("echo 'Database Error'; exit(0);")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('FIXTURES_STARTED', result.stdout)

    def test_isolated_bootstrap_reaches_fixtures(self):
        result = self.run_bootstrap("define('DB_NAME','mmaf_frontend_test'); function wp_get_environment_type(){return 'local';}")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, 'FIXTURES_STARTED')

    def test_non_test_database_is_rejected(self):
        result = self.run_bootstrap("define('DB_NAME','live'); function wp_get_environment_type(){return 'local';}")
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('FIXTURES_STARTED', result.stdout)

    def test_ci_defines_wordpress_root_before_private_config_gate(self):
        with tempfile.TemporaryDirectory() as directory:
            (Path(directory) / 'wp-config.php').write_text(
                "<?php if(!defined('ABSPATH')){exit(0);} "
                "define('DB_NAME','mmaf_frontend_test'); "
                "function wp_get_environment_type(){return 'local';}"
            )
            result = subprocess.run(
                [os.environ.get('MMAF_TEST_PHP', 'php'), '-r',
                 "require $argv[1]; echo 'FIXTURES_STARTED';", str(BOOTSTRAP)],
                env=dict(os.environ, MMAF_TEST_BOOTSTRAP=str(CI_BOOTSTRAP), MMAF_WP_CORE=directory),
                capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(result.stdout, 'FIXTURES_STARTED')


if __name__ == '__main__':
    unittest.main()
