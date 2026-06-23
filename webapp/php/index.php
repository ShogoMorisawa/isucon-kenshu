<?php
use Psr\Http\Message\ResponseInterface;
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;
use Slim\Factory\AppFactory;
use DI\Container;

require 'vendor/autoload.php';

$_SERVER += ['PATH_INFO' => $_SERVER['REQUEST_URI']];
$_SERVER['SCRIPT_NAME'] = '/' . basename($_SERVER['SCRIPT_FILENAME']);
$file = dirname(__DIR__) . '/public' . $_SERVER['REQUEST_URI'];
if (is_file($file)) {
    if (PHP_SAPI == 'cli-server') return false;
    $mimetype = [
        'js' => 'application/javascript',
        'css' => 'text/css',
        'ico' => 'image/vnd.microsoft.icon',
    ][pathinfo($file, PATHINFO_EXTENSION)] ?? false;
    if ($mimetype) {
        header("Content-Type: {$mimetype}");
        echo file_get_contents($file); exit;
    }
}

const POSTS_PER_PAGE = 20;
const UPLOAD_LIMIT = 10 * 1024 * 1024;

// memcached session
$memd_addr = '127.0.0.1:11211';
if (isset($_SERVER['ISUCONP_MEMCACHED_ADDRESS'])) {
    $memd_addr = $_SERVER['ISUCONP_MEMCACHED_ADDRESS'];
}
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', $memd_addr);

session_start();

// dependency
$container = new Container();
$container->set('settings', function() {
    return [
        'public_folder' => dirname(dirname(__FILE__)) . '/public',
        'db' => [
            'host' => $_SERVER['ISUCONP_DB_HOST'] ?? 'localhost',
            'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
            'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
            'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
            'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
        ],
    ];
});
$container->set('db', function ($c) {
    $config = $c->get('settings');
    return new PDO(
        "mysql:dbname={$config['db']['database']};host={$config['db']['host']};port={$config['db']['port']};charset=utf8mb4",
        $config['db']['username'],
        $config['db']['password'],
        // 永続接続でリクエスト毎の接続確立(TCP+認証)コストを削減。pm=static/16なので常駐接続は最大16前後
        [PDO::ATTR_PERSISTENT => true]
    );
});

$container->set('view', function ($c) {
    return new class(__DIR__ . '/views/') extends \Slim\Views\PhpRenderer {
        public function render(\Psr\Http\Message\ResponseInterface $response, string $template, array $data = []): ResponseInterface {
            $data += ['view' => $template];
            return parent::render($response, 'layout.php', $data);
        }
    };
});

$container->set('flash', function () {
    return new \Slim\Flash\Messages;
});

$container->set('helper', function ($c) {
    return new class($c) {
        public PDO $db;

        public function __construct($c) {
            $this->db = $c->get('db');
        }

        public function db() {
            return $this->db;
        }

        public function db_initialize() {
            $db = $this->db();
            $sql = [];
            $sql[] = 'DELETE FROM users WHERE id > 1000';
            $sql[] = 'DELETE FROM posts WHERE id > 10000';
            $sql[] = 'DELETE FROM comments WHERE id > 100000';
            $sql[] = 'UPDATE users SET del_flg = 0';
            $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
            foreach($sql as $s) {
                $db->query($s);
            }
        }

        public function fetch_first($query, ...$params) {
            $db = $this->db();
            $ps = $db->prepare($query);
            $ps->execute($params);
            $result = $ps->fetch();
            $ps->closeCursor();
            return $result;
        }

        public function try_login($account_name, $password) {
            $user = $this->fetch_first('SELECT * FROM users WHERE account_name = ? AND del_flg = 0', $account_name);
            if ($user !== false && calculate_passhash($user['account_name'], $password) == $user['passhash']) {
                return $user;
            } elseif ($user) {
                return null;
            } else {
                return null;
            }
        }

        public function get_session_user() {
            // ログイン時に id/account_name/authority をセッション(memcached)へ格納済みなのでDBを引かない
            if (!isset($_SESSION['user'], $_SESSION['user']['id'])) {
                return null;
            }
            return $_SESSION['user'];
        }

        // 指定id群のうち未キャッシュのユーザを1クエリ(IN)でまとめて取得し $cache に充填
        public function preload_users(array $ids, array &$cache) {
            $missing = [];
            foreach (array_unique($ids) as $id) {
                if (!array_key_exists($id, $cache)) {
                    $missing[] = $id;
                }
            }
            if (!$missing) {
                return;
            }
            $ph = implode(',', array_fill(0, count($missing), '?'));
            $ps = $this->db()->prepare("SELECT * FROM `users` WHERE `id` IN ({$ph})");
            $ps->execute($missing);
            foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $cache[$u['id']] = $u;
            }
        }

        public function make_posts(array $results, $options = []) {
            $options += ['all_comments' => false];
            $all_comments = $options['all_comments'];

            // ユーザはリクエスト内でメモ化＋IN句一括取得
            $user_cache = [];
            $get_user = function ($id) use (&$user_cache) {
                if (!array_key_exists($id, $user_cache)) {
                    $user_cache[$id] = $this->fetch_first('SELECT * FROM `users` WHERE `id` = ?', $id);
                }
                return $user_cache[$id];
            };

            // 1) 表示対象postを先に確定（del_flg=0 の投稿を最大POSTS_PER_PAGE件）。post-userは一括取得
            $this->preload_users(array_map(fn($p) => $p['user_id'], $results), $user_cache);
            $selected = [];
            foreach ($results as $post) {
                $u = $get_user($post['user_id']);
                if ($u && $u['del_flg'] == 0) {
                    $post['user'] = $u;
                    $selected[] = $post;
                    if (count($selected) >= POSTS_PER_PAGE) {
                        break;
                    }
                }
            }
            if (!$selected) {
                return [];
            }

            // 2) 選択post群のコメントを1クエリでまとめて取得（DESC）。件数もこの結果から算出
            $post_ids = array_map(fn($p) => $p['id'], $selected);
            $ph = implode(',', array_fill(0, count($post_ids), '?'));
            $ps = $this->db()->prepare("SELECT `id`,`post_id`,`user_id`,`comment`,`created_at` FROM `comments` WHERE `post_id` IN ({$ph}) ORDER BY `created_at` DESC");
            $ps->execute($post_ids);
            $all = $ps->fetchAll(PDO::FETCH_ASSOC);

            // 3) コメントのuserも一括取得
            $this->preload_users(array_map(fn($c) => $c['user_id'], $all), $user_cache);

            $comments_by_post = [];
            $count_by_post = [];
            foreach ($all as $c) {
                $pid = $c['post_id'];
                $count_by_post[$pid] = ($count_by_post[$pid] ?? 0) + 1;
                // 表示は最新3件（all_commentsなら全件）。$all はDESC順なので先頭から
                if ($all_comments || count($comments_by_post[$pid] ?? []) < 3) {
                    $c['user'] = $get_user($c['user_id']);
                    $comments_by_post[$pid][] = $c;
                }
            }

            $posts = [];
            foreach ($selected as $post) {
                $pid = $post['id'];
                $post['comment_count'] = $count_by_post[$pid] ?? 0;
                $post['comments'] = array_reverse($comments_by_post[$pid] ?? []);
                $posts[] = $post;
            }
            return $posts;
        }

    };
});

AppFactory::setContainer($container);
$app = AppFactory::create();

// ------- helper method for view

function escape_html($h) {
    return htmlspecialchars($h, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function redirect(Response $response, $location, $status) {
    return $response->withStatus($status)->withHeader('Location', $location);
}

function image_url($post) {
    $ext = '';
    if ($post['mime'] === 'image/jpeg') {
        $ext = '.jpg';
    } else if ($post['mime'] === 'image/png') {
        $ext = '.png';
    } else if ($post['mime'] === 'image/gif') {
        $ext = '.gif';
    }
    return "/image/{$post['id']}{$ext}";
}

// アプリ用 memcached（セッションとは別の永続接続）。フィード/詳細キャッシュに使用。
function feed_cache() {
    static $mc = null;
    if ($mc === null) {
        $mc = new Memcached('feedpool');
        if (!count($mc->getServerList())) {
            $mc->addServer('127.0.0.1', 11211);
        }
    }
    return $mc;
}

// フィードのグローバルバージョン。投稿/コメント追加で bump し、古いキャッシュキーを参照させない。
function feed_version() {
    $mc = feed_cache();
    $v = $mc->get('feed_version');
    if ($v === false) {
        $mc->add('feed_version', 1);
        $v = $mc->get('feed_version');
        $v = ($v === false) ? 1 : $v;
    }
    return $v;
}
function bump_feed_version() {
    $mc = feed_cache();
    if ($mc->increment('feed_version', 1) === false) {
        $mc->add('feed_version', 1);
        $mc->increment('feed_version', 1);
    }
}

// csrf_token はユーザ固有なので断片HTMLにはプレースホルダを埋め、最後に実トークンへ置換する
const CSRF_PLACEHOLDER = '@@CSRFTOKEN@@';

// 1投稿分の post.php をHTML文字列にレンダリング（csrfはプレースホルダ）。
function render_one_post_fragment(array $post) {
    $csrf_token = CSRF_PLACEHOLDER; // post.php が参照する
    ob_start();
    require __DIR__ . '/views/post.php';
    return ob_get_clean();
}

// 投稿リストのHTMLを断片キャッシュで組み立てる。
// 断片キーは (post_id, comment_count)。コメント追加で comment_count が変わり自動的に別キー＝自動invalidate。
// 投稿本文/画像/ユーザ名/最新3コメントは全ユーザ共通なので断片はユーザ間で再利用でき、csrfだけ後段で合成する。
function render_post_list(array $posts) {
    $mc = feed_cache();
    $keys = [];
    foreach ($posts as $p) {
        $keys[$p['id']] = 'pf:' . (int)$p['id'] . ':c' . (int)($p['comment_count'] ?? 0);
    }
    $found = $keys ? $mc->getMulti(array_values($keys)) : [];
    if ($found === false) {
        $found = [];
    }
    $html = '';
    $to_set = [];
    foreach ($posts as $p) {
        $k = $keys[$p['id']];
        if (isset($found[$k])) {
            $html .= $found[$k];
        } else {
            $frag = render_one_post_fragment($p);
            $to_set[$k] = $frag;
            $html .= $frag;
        }
    }
    if ($to_set) {
        $mc->setMulti($to_set, 600);
    }
    // ユーザ固有の csrf を最後に1回だけ合成
    return str_replace(CSRF_PLACEHOLDER, escape_html($_SESSION['csrf_token'] ?? ''), $html);
}

function validate_user($account_name, $password) {
    if (!(preg_match('/\A[0-9a-zA-Z_]{3,}\z/', $account_name) && preg_match('/\A[0-9a-zA-Z_]{6,}\z/', $password))) {
        return false;
    }
    return true;
}

function digest($src) {
    // 旧実装は `printf | openssl dgst -sha512 | sed` の外部プロセス起動。
    // PHPネイティブhashとバイト一致を確認済みのため置換（プロセスfork/exec排除）。
    return hash('sha512', $src);
}

function calculate_salt($account_name) {
    return digest($account_name);
}

function calculate_passhash($account_name, $password) {
    $salt = calculate_salt($account_name);
    return digest("{$password}:{$salt}");
}

// --------

$app->get('/initialize', function (Request $request, Response $response) {
    $this->get('helper')->db_initialize();
    // DBリセットに伴い、古いフィード/詳細キャッシュを全消去（initializeは最初に呼ばれ、保持すべきセッションは無い）
    feed_cache()->flush();
    // db_initialize は posts id>10000 を削除する。対応する画像ファイルもここで掃除し
    // ベンチ毎のアップロード画像がディスクに累積してフルになるのを防ぐ。
    $imgdir = dirname(__DIR__) . '/public/image';
    foreach (glob("{$imgdir}/*") as $f) {
        $id = (int)basename($f);
        if ($id > 10000) {
            @unlink($f);
        }
    }
    return $response;
});

$app->get('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'login.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->post('/login', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }

    $db = $this->get('db');
    $params = $request->getParsedBody();
    $user = $this->get('helper')->try_login($params['account_name'], $params['password']);

    if ($user) {
        $_SESSION['user'] = [
            'id' => $user['id'],
            'account_name' => $user['account_name'],
            'authority' => $user['authority'],
        ];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
        return redirect($response, '/', 302);
    } else {
        $this->get('flash')->addMessage('notice', 'アカウント名かパスワードが間違っています');
        return redirect($response, '/login', 302);
    }
});

$app->get('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user() !== null) {
        return redirect($response, '/', 302);
    }
    return $this->get('view')->render($response, 'register.php', [
        'me' => null,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});


$app->post('/register', function (Request $request, Response $response) {
    if ($this->get('helper')->get_session_user()) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    $account_name = $params['account_name'];
    $password = $params['password'];

    $validated = validate_user($account_name, $password);
    if (!$validated) {
        $this->get('flash')->addMessage('notice', 'アカウント名は3文字以上、パスワードは6文字以上である必要があります');
        return redirect($response, '/register', 302);
    }

    $user = $this->get('helper')->fetch_first('SELECT 1 FROM users WHERE `account_name` = ?', $account_name);
    if ($user) {
        $this->get('flash')->addMessage('notice', 'アカウント名がすでに使われています');
        return redirect($response, '/register', 302);
    }

    $db = $this->get('db');
    $ps = $db->prepare('INSERT INTO `users` (`account_name`, `passhash`) VALUES (?,?)');
    $ps->execute([
        $account_name,
        calculate_passhash($account_name, $password)
    ]);
    $_SESSION['user'] = [
        'id' => $db->lastInsertId(),
        'account_name' => $account_name,
        'authority' => 0,
    ];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return redirect($response, '/', 302);
});

$app->get('/logout', function (Request $request, Response $response) {
    unset($_SESSION['user']);
    unset($_SESSION['csrf_token']);
    return redirect($response, '/', 302);
});

$app->get('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    // フィード($posts)は全ユーザ共通。memcachedにキャッシュし、投稿/コメント時のfeed_versionバンプで無効化。
    // me/csrf/flash はキャッシュ外でテンプレ合成するのでユーザ別表示は保たれる。
    // ※匿名フルHTMLキャッシュも試したが効果無し(GET /は大半が認証済トラフィックでanon cacheがヒットしない)→不採用。
    $mc = feed_cache();
    $ckey = 'index:v' . feed_version();
    $posts = $mc->get($ckey);
    if ($posts === false) {
        $db = $this->get('db');
        // idx_created_at の逆順読みでfilesort回避。del_flg除外はmake_posts内。母数をLIMITで制限(削除ユーザ~2%なので40で20件は確実)
        $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` FORCE INDEX (idx_created_at) ORDER BY `created_at` DESC LIMIT 40');
        $ps->execute();
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $this->get('helper')->make_posts($results);
        $mc->set($ckey, $posts, 10);
    }

    return $this->get('view')->render($response, 'index.php', [
        'posts' => $posts,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    // ※/posts キャッシュは低頻度＋max_created_at別でヒット率低く効果無し(stage計測で~188k<192k)のため未キャッシュ
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` FORCE INDEX (idx_created_at) WHERE `created_at` <= ? ORDER BY `created_at` DESC LIMIT 40');
    $ps->execute([$max_created_at === null ? null : $max_created_at]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    return $this->get('view')->render($response, 'posts.php', ['posts' => $posts]);
});

$app->get('/posts/{id}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` WHERE `id` = ?');
    $ps->execute([$args['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results, ['all_comments' => true]);

    if (count($posts) == 0) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $post = $posts[0];

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'post.php', ['post' => $post, 'me' => $me, 'csrf_token' => $_SESSION['csrf_token'] ?? '']);
});

$app->post('/', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if ($_FILES['file']) {
        $mime = '';
        // 投稿のContent-Typeからファイルのタイプを決定する
        if (strpos($_FILES['file']['type'], 'jpeg') !== false) {
            $mime = 'image/jpeg';
        } elseif (strpos($_FILES['file']['type'], 'png') !== false) {
            $mime = 'image/png';
        } elseif (strpos($_FILES['file']['type'], 'gif') !== false) {
            $mime = 'image/gif';
        } else {
            $this->get('flash')->addMessage('notice', '投稿できる画像形式はjpgとpngとgifだけです');
            return redirect($response, '/', 302);
        }

        // tmpは1回だけ読む
        $data = file_get_contents($_FILES['file']['tmp_name']);
        if (strlen($data) > UPLOAD_LIMIT) {
            $this->get('flash')->addMessage('notice', 'ファイルサイズが大きすぎます');
            return redirect($response, '/', 302);
        }

        $db = $this->get('db');
        // imgdataはDBに保存しない（ファイル配信のみ）。imgdataはNOT NULLなので空文字を入れる
        $query = 'INSERT INTO `posts` (`user_id`, `mime`, `imgdata`, `body`) VALUES (?,?,?,?)';
        $ps = $db->prepare($query);
        $ps->execute([
          $me['id'],
          $mime,
          '',
          $params['body'],
        ]);
        $pid = $db->lastInsertId();
        // 画像を静的ファイルへ書き出し（nginx直配信用・GET /image はこのファイルに依存）
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'][$mime] ?? '';
        if ($ext !== '') {
            $imgdir = dirname(__DIR__) . '/public/image';
            file_put_contents("{$imgdir}/{$pid}.{$ext}", $data);
        }
        // 新規投稿はフィード先頭に出る → キャッシュ無効化
        bump_feed_version();
        return redirect($response, "/posts/{$pid}", 302);
    } else {
        $this->get('flash')->addMessage('notice', '画像が必須です');
        return redirect($response, '/', 302);
    }
});

$app->get('/image/{id}.{ext}', function (Request $request, Response $response, $args) {
    if ($args['id'] == 0) {
        return $response;
    }

    // 画像はファイル配信のみ（imgdataはDBに保持しない）。通常はnginxが直配信し、ここに来るのはファイル未存在時。
    // 念のためファイルが在ればPHPからも返す（nginxのnegativeキャッシュ対策）。無ければ404。
    $imgdir = dirname(__DIR__) . '/public/image';
    $path = "{$imgdir}/{$args['id']}.{$args['ext']}";
    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'][$args['ext']] ?? '';
    if ($mime !== '' && is_file($path)) {
        $response->getBody()->write(file_get_contents($path));
        return $response->withHeader('Content-Type', $mime);
    }
    $response->getBody()->write('404');
    return $response->withStatus(404);
});

$app->post('/comment', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    if (!preg_match('/\A[0-9]+\z/', $params['post_id'])) {
        $response->getBody()->write('post_idは整数のみです');
        return $response;
    }
    $post_id = $params['post_id'];

    $query = 'INSERT INTO `comments` (`post_id`, `user_id`, `comment`) VALUES (?,?,?)';
    $ps = $this->get('db')->prepare($query);
    $ps->execute([
        $post_id,
        $me['id'],
        $params['comment']
    ]);

    // コメント追加でフィードのコメント数/最新3件が変わる → キャッシュ無効化
    bump_feed_version();

    return redirect($response, "/posts/{$post_id}", 302);
});

$app->get('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/login', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $db = $this->get('db');
    $ps = $db->prepare('SELECT * FROM `users` WHERE `authority` = 0 AND `del_flg` = 0 ORDER BY `created_at` DESC');
    $ps->execute();
    $users = $ps->fetchAll(PDO::FETCH_ASSOC);

    return $this->get('view')->render($response, 'banned.php', ['users' => $users, 'me' => $me]);
});

$app->post('/admin/banned', function (Request $request, Response $response) {
    $me = $this->get('helper')->get_session_user();

    if ($me === null) {
        return redirect($response, '/', 302);
    }

    if ($me['authority'] == 0) {
        $response->getBody()->write('403');
        return $response->withStatus(403);
    }

    $params = $request->getParsedBody();
    if (($params['csrf_token'] ?? null) !== $_SESSION['csrf_token']) {
        $response->getBody()->write('422');
        return $response->withStatus(422);
    }

    $db = $this->get('db');
    $query = 'UPDATE `users` SET `del_flg` = ? WHERE `id` = ?';
    foreach ($params['uid'] as $id) {
        $ps = $db->prepare($query);
        $ps->execute([1, $id]);
    }

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $ps = $db->prepare('SELECT `id`, `user_id`, `body`, `created_at`, `mime` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC');
    $ps->execute([$user['id']]);
    $results = $ps->fetchAll(PDO::FETCH_ASSOC);
    $posts = $this->get('helper')->make_posts($results);

    $comment_count = $this->get('helper')->fetch_first('SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?', $user['id'])['count'];

    $ps = $db->prepare('SELECT `id` FROM `posts` WHERE `user_id` = ?');
    $ps->execute([$user['id']]);
    $post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');
    $post_count = count($post_ids);

    $commented_count = 0;
    if ($post_count > 0) {
        $placeholder = implode(',', array_fill(0, count($post_ids), '?'));
        $commented_count = $this->get('helper')->fetch_first("SELECT COUNT(*) AS count FROM `comments` WHERE `post_id` IN ({$placeholder})", ...$post_ids)['count'];
    }

    $me = $this->get('helper')->get_session_user();

    return $this->get('view')->render($response, 'user.php', ['posts' => $posts, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
