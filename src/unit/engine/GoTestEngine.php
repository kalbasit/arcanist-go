<?php

/**
 * Go test Runner
 */
final class GoTestEngine extends ArcanistUnitTestEngine {

  const USE_GODEP_KEY = 'unit.go.godep';
  const USE_RACE_KEY = 'unit.go.race';
  private $projectRoot;

  public function run() {
    $this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
    $cmdTmpl = $this->getCommandTemplate();

    if ($this->getRunAllTests()) {
      $paths = id(new FileFinder($this->projectRoot))
        ->excludePath('./.git')
        ->excludePath('./.git/*')
        ->withType('d')
        ->find();
    } else {
      $paths = $this->getPaths();
    }

    $futures = $this->buildFutures($paths, $cmdTmpl);
    if (empty($futures)) {
      throw new ArcanistNoEffectException('No tests to run.');
    }

    $results = array();
    foreach ($futures as $package => $future) {
      $results = array_merge(
        $results,
        $this->resolveFuture($package, $future));
    }

    return $results;
  }

  protected function getBinary() {
    return 'go';
  }

  protected function supportsRunAllTests() {
    return true;
  }

  protected function getVersion() {
    $cmd = csprintf('%s version', $this->getBinary());
    list($stdout) = execx('%C', $cmd);
    $matches = array();
    preg_match(
      '/^go version go(?P<version>[0-9\.]+).*/',
      $stdout,
      $matches);
    return $matches['version'];
  }

  protected function getDefaultConfig() {
    return array(
      self::USE_GODEP_KEY => true,
      self::USE_RACE_KEY  => true,
    );
  }

  protected function getCommandTemplate() {
    $cmd = '';
    if ($this->useGodep()) {
      $cmd = 'godep ';
    }

    $cmd .= 'go test -v';

    if ($this->useRace()) {
      $cmd .= ' -race';
    }

    $cmd .= ' ./';

    return $cmd;
  }

  protected function useRace() {
    $default = idx($this->getDefaultConfig(), self::USE_RACE_KEY);
    if ($this->getConfig(self::USE_RACE_KEY, $default) === false) {
      return false;
    }

    $version = explode('.', $this->getVersion());
    if ($version[0] == 1 && $version[1] < 1) {
      return false;
    }

    return true;
  }

  protected function useGodep() {
    $default = idx($this->getDefaultConfig(), self::USE_GODEP_KEY);
    if ($this->getConfig(self::USE_GODEP_KEY, $default) === false) {
      return false;
    }

    if (is_dir(Filesystem::resolvePath('Godeps', $this->projectRoot))) {
      return true;
    }

    return false;
  }

  protected function getConfig($key, $default = null) {
    return $this->getConfigurationManager()->getConfigFromAnySource(
      $key,
      $default);
  }

  protected function buildFutures(array $packages, $cmd_tmpl) {
    $affected_packages = array();
    foreach ($packages as $package) {
      // Must always test a package.
      if (!is_dir($package)) {
        // If it's a file but not a go file. Skip this test
        if (substr($package, -3) != '.go') {
          continue;
        }

        $package = dirname($package);
      }

      // The package must exist!
      if (!file_exists($package)) {
        // The entire folder was removed so we should not run any tests.
        continue;
      }

      if (!array_key_exists($package, $affected_packages)) {
        $affected_packages[] = $package;
      }
    }

    $futures = array();
    foreach ($affected_packages as $package) {
      if ($package === '.') {
        $package = '';
      }

      $future = new ExecFuture(
        '%C%C',
        $cmd_tmpl,
        $package);
      $future->setCWD($this->projectRoot);
      $futures[$package] = $future;
    }

    return $futures;
  }

  protected function resolveFuture($package, Future $future) {
    list($err, $stdout, $stderr) = $future->resolve();
    $parser = new ArcanistGoTestResultParser();
    $messages = $parser->parseTestResults($package, $stdout, $stderr);

    if ($messages === false) {
      if ($err) {
        $future->resolvex();
      } else {
        throw new Exception(
          sprintf(
            "%s\n\nSTDOUT\n%s\n\nSTDERR\n%s",
            pht('Linter failed to parse output!'),
            $stdout,
            $stderr));
      }
    }

    return $messages;
  }
}
