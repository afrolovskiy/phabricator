<?php

final class PhabricatorRepositoryQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $callsigns;
  private $types;
  private $uuids;
  private $nameContains;
  private $remoteURIs;
  private $datasourceQuery;

  private $numericIdentifiers;
  private $callsignIdentifiers;
  private $phidIdentifiers;

  private $identifierMap;

  const STATUS_OPEN = 'status-open';
  const STATUS_CLOSED = 'status-closed';
  const STATUS_ALL = 'status-all';
  private $status = self::STATUS_ALL;

  const HOSTED_PHABRICATOR = 'hosted-phab';
  const HOSTED_REMOTE = 'hosted-remote';
  const HOSTED_ALL = 'hosted-all';
  private $hosted = self::HOSTED_ALL;

  private $needMostRecentCommits;
  private $needCommitCounts;
  private $needProjectPHIDs;

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withCallsigns(array $callsigns) {
    $this->callsigns = $callsigns;
    return $this;
  }

  public function withIdentifiers(array $identifiers) {
    $ids = array(); $callsigns = array(); $phids = array();
    foreach ($identifiers as $identifier) {
      if (ctype_digit($identifier)) {
        $ids[$identifier] = $identifier;
      } else {
        $repository_type = PhabricatorRepositoryRepositoryPHIDType::TYPECONST;
        if (phid_get_type($identifier) === $repository_type) {
          $phids[$identifier] = $identifier;
        } else {
          $callsigns[$identifier] = $identifier;
        }
      }
    }

    $this->numericIdentifiers = $ids;
    $this->callsignIdentifiers = $callsigns;
    $this->phidIdentifiers = $phids;
    return $this;
  }

  public function withStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function withHosted($hosted) {
    $this->hosted = $hosted;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  public function withUUIDs(array $uuids) {
    $this->uuids = $uuids;
    return $this;
  }

  public function withNameContains($contains) {
    $this->nameContains = $contains;
    return $this;
  }

  public function withRemoteURIs(array $uris) {
    $this->remoteURIs = $uris;
    return $this;
  }

  public function withDatasourceQuery($query) {
    $this->datasourceQuery = $query;
    return $this;
  }

  public function needCommitCounts($need_counts) {
    $this->needCommitCounts = $need_counts;
    return $this;
  }

  public function needMostRecentCommits($need_commits) {
    $this->needMostRecentCommits = $need_commits;
    return $this;
  }

  public function needProjectPHIDs($need_phids) {
    $this->needProjectPHIDs = $need_phids;
    return $this;
  }

  public function getBuiltinOrders() {
    return array(
      'committed' => array(
        'vector' => array('committed', 'id'),
        'name' => pht('Most Recent Commit'),
      ),
      'name' => array(
        'vector' => array('name', 'id'),
        'name' => pht('Name'),
      ),
      'callsign' => array(
        'vector' => array('callsign'),
        'name' => pht('Callsign'),
      ),
      'size' => array(
        'vector' => array('size', 'id'),
        'name' => pht('Size'),
      ),
    ) + parent::getBuiltinOrders();
  }

  public function getIdentifierMap() {
    if ($this->identifierMap === null) {
      throw new Exception(
        'You must execute() the query before accessing the identifier map.');
    }
    return $this->identifierMap;
  }

  protected function willExecute() {
    $this->identifierMap = array();
  }

  protected function loadPage() {
    $table = new PhabricatorRepository();
    $conn_r = $table->establishConnection('r');

    $data = queryfx_all(
      $conn_r,
      '%Q FROM %T r %Q %Q %Q %Q %Q %Q',
      $this->buildSelectClause($conn_r),
      $table->getTableName(),
      $this->buildJoinClause($conn_r),
      $this->buildWhereClause($conn_r),
      $this->buildGroupClause($conn_r),
      $this->buildHavingClause($conn_r),
      $this->buildOrderClause($conn_r),
      $this->buildLimitClause($conn_r));

    $repositories = $table->loadAllFromArray($data);

    if ($this->needCommitCounts) {
      $sizes = ipull($data, 'size', 'id');
      foreach ($repositories as $id => $repository) {
        $repository->attachCommitCount(nonempty($sizes[$id], 0));
      }
    }

    if ($this->needMostRecentCommits) {
      $commit_ids = ipull($data, 'lastCommitID', 'id');
      $commit_ids = array_filter($commit_ids);
      if ($commit_ids) {
        $commits = id(new DiffusionCommitQuery())
          ->setViewer($this->getViewer())
          ->withIDs($commit_ids)
          ->execute();
      } else {
        $commits = array();
      }
      foreach ($repositories as $id => $repository) {
        $commit = null;
        if (idx($commit_ids, $id)) {
          $commit = idx($commits, $commit_ids[$id]);
        }
        $repository->attachMostRecentCommit($commit);
      }
    }

    return $repositories;
  }

  protected function willFilterPage(array $repositories) {
    assert_instances_of($repositories, 'PhabricatorRepository');

    // TODO: Denormalize repository status into the PhabricatorRepository
    // table so we can do this filtering in the database.
    foreach ($repositories as $key => $repo) {
      $status = $this->status;
      switch ($status) {
        case self::STATUS_OPEN:
          if (!$repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_CLOSED:
          if ($repo->isTracked()) {
            unset($repositories[$key]);
          }
          break;
        case self::STATUS_ALL:
          break;
        default:
          throw new Exception("Unknown status '{$status}'!");
      }

      // TODO: This should also be denormalized.
      $hosted = $this->hosted;
      switch ($hosted) {
        case self::HOSTED_PHABRICATOR:
          if (!$repo->isHosted()) {
            unset($repositories[$key]);
          }
          break;
        case self::HOSTED_REMOTE:
          if ($repo->isHosted()) {
            unset($repositories[$key]);
          }
          break;
        case self::HOSTED_ALL:
          break;
        default:
          throw new Exception("Uknown hosted failed '${hosted}'!");
      }
    }

    // TODO: Denormalize this, too.
    if ($this->remoteURIs) {
      $try_uris = $this->getNormalizedPaths();
      $try_uris = array_fuse($try_uris);
      foreach ($repositories as $key => $repository) {
        if (!isset($try_uris[$repository->getNormalizedPath()])) {
          unset($repositories[$key]);
        }
      }
    }

    // Build the identifierMap
    if ($this->numericIdentifiers) {
      foreach ($this->numericIdentifiers as $id) {
        if (isset($repositories[$id])) {
          $this->identifierMap[$id] = $repositories[$id];
        }
      }
    }

    if ($this->callsignIdentifiers) {
      $repository_callsigns = mpull($repositories, null, 'getCallsign');

      foreach ($this->callsignIdentifiers as $callsign) {
        if (isset($repository_callsigns[$callsign])) {
          $this->identifierMap[$callsign] = $repository_callsigns[$callsign];
        }
      }
    }

    if ($this->phidIdentifiers) {
      $repository_phids = mpull($repositories, null, 'getPHID');

      foreach ($this->phidIdentifiers as $phid) {
        if (isset($repository_phids[$phid])) {
          $this->identifierMap[$phid] = $repository_phids[$phid];
        }
      }
    }

    return $repositories;
  }

  protected function didFilterPage(array $repositories) {
    if ($this->needProjectPHIDs) {
      $type_project = PhabricatorProjectObjectHasProjectEdgeType::EDGECONST;

      $edge_query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs(mpull($repositories, 'getPHID'))
        ->withEdgeTypes(array($type_project));
      $edge_query->execute();

      foreach ($repositories as $repository) {
        $project_phids = $edge_query->getDestinationPHIDs(
          array(
            $repository->getPHID(),
          ));
        $repository->attachProjectPHIDs($project_phids);
      }
    }

    return $repositories;
  }

  protected function getPrimaryTableAlias() {
    return 'r';
  }

  public function getOrderableColumns() {
    return parent::getOrderableColumns() + array(
      'committed' => array(
        'table' => 's',
        'column' => 'epoch',
        'type' => 'int',
        'null' => 'tail',
      ),
      'callsign' => array(
        'table' => 'r',
        'column' => 'callsign',
        'type' => 'string',
        'unique' => true,
        'reverse' => true,
      ),
      'name' => array(
        'table' => 'r',
        'column' => 'name',
        'type' => 'string',
        'reverse' => true,
      ),
      'size' => array(
        'table' => 's',
        'column' => 'size',
        'type' => 'int',
        'null' => 'tail',
      ),
    );
  }

  protected function willExecuteCursorQuery(
    PhabricatorCursorPagedPolicyAwareQuery $query) {
    $vector = $this->getOrderVector();

    if ($vector->containsKey('committed')) {
      $query->needMostRecentCommits(true);
    }

    if ($vector->containsKey('size')) {
      $query->needCommitCounts(true);
    }
  }

  protected function getPagingValueMap($cursor, array $keys) {
    $repository = $this->loadCursorObject($cursor);

    $map = array(
      'id' => $repository->getID(),
      'callsign' => $repository->getCallsign(),
      'name' => $repository->getName(),
    );

    foreach ($keys as $key) {
      switch ($key) {
        case 'committed':
          $commit = $repository->getMostRecentCommit();
          if ($commit) {
            $map[$key] = $commit->getEpoch();
          } else {
            $map[$key] = null;
          }
          break;
        case 'size':
          $count = $repository->getCommitCount();
          if ($count) {
            $map[$key] = $count;
          } else {
            $map[$key] = null;
          }
          break;
      }
    }

    return $map;
  }

  protected function buildSelectClause(AphrontDatabaseConnection $conn) {
    $parts = $this->buildSelectClauseParts($conn);
    if ($this->shouldJoinSummaryTable()) {
      $parts[] = 's.*';
    }
    return $this->formatSelectClause($parts);
  }

  protected function buildJoinClause(AphrontDatabaseConnection $conn_r) {
    $joins = $this->buildJoinClauseParts($conn_r);

    if ($this->shouldJoinSummaryTable()) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T s ON r.id = s.repositoryID',
        PhabricatorRepository::TABLE_SUMMARY);
    }

    return $this->formatJoinClause($joins);
  }

  private function shouldJoinSummaryTable() {
    if ($this->needCommitCounts) {
      return true;
    }

    if ($this->needMostRecentCommits) {
      return true;
    }

    $vector = $this->getOrderVector();
    if ($vector->containsKey('committed')) {
      return true;
    }

    if ($vector->containsKey('size')) {
      return true;
    }

    return false;
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn_r) {
    $where = parent::buildWhereClauseParts($conn_r);

    if ($this->ids) {
      $where[] = qsprintf(
        $conn_r,
        'r.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids) {
      $where[] = qsprintf(
        $conn_r,
        'r.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->callsigns) {
      $where[] = qsprintf(
        $conn_r,
        'r.callsign IN (%Ls)',
        $this->callsigns);
    }

    if ($this->numericIdentifiers ||
      $this->callsignIdentifiers ||
      $this->phidIdentifiers) {
      $identifier_clause = array();

      if ($this->numericIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.id IN (%Ld)',
          $this->numericIdentifiers);
      }

      if ($this->callsignIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.callsign IN (%Ls)',
          $this->callsignIdentifiers);
      }

      if ($this->phidIdentifiers) {
        $identifier_clause[] = qsprintf(
          $conn_r,
          'r.phid IN (%Ls)',
          $this->phidIdentifiers);
      }

      $where = array('('.implode(' OR ', $identifier_clause).')');
    }

    if ($this->types) {
      $where[] = qsprintf(
        $conn_r,
        'r.versionControlSystem IN (%Ls)',
        $this->types);
    }

    if ($this->uuids) {
      $where[] = qsprintf(
        $conn_r,
        'r.uuid IN (%Ls)',
        $this->uuids);
    }

    if (strlen($this->nameContains)) {
      $where[] = qsprintf(
        $conn_r,
        'name LIKE %~',
        $this->nameContains);
    }

    if (strlen($this->datasourceQuery)) {
      // This handles having "rP" match callsigns starting with "P...".
      $query = trim($this->datasourceQuery);
      if (preg_match('/^r/', $query)) {
        $callsign = substr($query, 1);
      } else {
        $callsign = $query;
      }
      $where[] = qsprintf(
        $conn_r,
        'r.name LIKE %> OR r.callsign LIKE %>',
        $query,
        $callsign);
    }

    return $where;
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorDiffusionApplication';
  }

  private function getNormalizedPaths() {
    $normalized_uris = array();

    // Since we don't know which type of repository this URI is in the general
    // case, just generate all the normalizations. We could refine this in some
    // cases: if the query specifies VCS types, or the URI is a git-style URI
    // or an `svn+ssh` URI, we could deduce how to normalize it. However, this
    // would be more complicated and it's not clear if it matters in practice.

    foreach ($this->remoteURIs as $uri) {
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_GIT,
        $uri);
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_SVN,
        $uri);
      $normalized_uris[] = new PhabricatorRepositoryURINormalizer(
        PhabricatorRepositoryURINormalizer::TYPE_MERCURIAL,
        $uri);
    }

    return array_unique(mpull($normalized_uris, 'getNormalizedPath'));
  }

}
