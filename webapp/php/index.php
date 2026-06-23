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
// csrf_token はユーザ固有なので断片HTMLにはプレースホルダを埋め、最後に実トークンへ置換する（高速パスより前で定義）
const CSRF_PLACEHOLDER = '@@CSRFTOKEN@@';

// memcached session
$memd_addr = '127.0.0.1:11211';
if (isset($_SERVER['ISUCONP_MEMCACHED_ADDRESS'])) {
    $memd_addr = $_SERVER['ISUCONP_MEMCACHED_ADDRESS'];
}
ini_set('session.save_handler', 'memcached');
ini_set('session.save_path', $memd_addr);

// セッションは「cookieがある＝ログイン済みの可能性がある」時のみ開始する。
// 匿名のcookie無しリクエスト(GET /等の大半)では memcached 読み書き往復・Set-Cookie をまるごと省く。
// 書き込み経路(login/register)は ensure_session() で明示的に開始する。
if (isset($_COOKIE[session_name()])) {
    session_start();
}

function ensure_session() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

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
    // セッションが開始済みなら $_SESSION を、無ければ使い捨て配列を storage に使う。
    // 遅延セッション化により匿名リクエストでは $_SESSION が無いため、Flashが「Session not found」で落ちるのを防ぐ。
    if (session_status() === PHP_SESSION_ACTIVE) {
        return new \Slim\Flash\Messages();
    }
    $throwaway = [];
    return new \Slim\Flash\Messages($throwaway);
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
            // comment_count 非正規化列を再集計（DELETE後の残データに合わせる）
            $sql[] = 'UPDATE posts SET comment_count = 0';
            $sql[] = 'UPDATE posts p JOIN (SELECT post_id, COUNT(*) c FROM comments GROUP BY post_id) x ON p.id = x.post_id SET p.comment_count = x.c';
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

// === 高速パス: 頻出GET endpointを Slim 非経由で直接レンダリング（framework固定費削減） ===
// ★ AppFactory::create() より前に置くことで、高速パス該当時は Slim App/Router/Middleware の生成自体をスキップ。
// テンプレ・変数は通常(Slim)経路と同一を使い出力HTML/Content-Typeはバイト一致（各endpointでdiff検証済）。
// 該当しない/見つからない場合は exit せず下の Slim 初期化＋ルーティングへフォールスルー。
// 使用する関数(build_list_html等)は top-level 宣言でホイストされ、CSRF_PLACEHOLDER は冒頭で定義済。
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fast_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    if ($fast_path === '/') {
        $helper = $container->get('helper');
        $me = $helper->get_session_user();
        $ps = $helper->db()->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` USE INDEX (idx_feed) ORDER BY `created_at` DESC LIMIT 40');
        $ps->execute();
        $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
        $post_list_html = build_list_html($helper, $rows);
        $flash = $container->get('flash')->getFirstMessage('notice');
        $view = 'index.php';
        require __DIR__ . '/views/layout.php';
        exit;
    } elseif (preg_match('#^/posts/(\d+)$#', $fast_path, $fm)) {
        // GET /posts/{id} 投稿詳細（全コメント）。存在時のみ高速描画、404は Slim へフォールスルー。
        $helper = $container->get('helper');
        $ps = $helper->db()->prepare('SELECT `id`, `user_id`, `body`, `mime`, `created_at` FROM `posts` WHERE `id` = ?');
        $ps->execute([(int)$fm[1]]);
        $results = $ps->fetchAll(PDO::FETCH_ASSOC);
        $posts = $helper->make_posts($results, ['all_comments' => true]);
        if (count($posts) > 0) {
            $post = $posts[0];
            $me = $helper->get_session_user();
            $csrf_token = $_SESSION['csrf_token'] ?? '';
            $view = 'post.php';
            require __DIR__ . '/views/layout.php';
            exit;
        }
        // 見つからなければ Slim 経路へ（404 応答を共通化）
    } elseif ($fast_path === '/posts') {
        // GET /posts ページング。※Slim経路は 'me' を渡さない＝ヘッダは常にログインリンク。$me を定義しないことで一致。
        $helper = $container->get('helper');
        $max_created_at = $_GET['max_created_at'] ?? null;
        $ps = $helper->db()->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` USE INDEX (idx_feed) WHERE `created_at` <= ? ORDER BY `created_at` DESC LIMIT 40');
        $ps->execute([$max_created_at === null ? null : $max_created_at]);
        $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
        $post_list_html = build_list_html($helper, $rows);
        $view = 'posts.php';
        require __DIR__ . '/views/layout.php';
        exit;
    } elseif (preg_match('#^/@([^/]+)$#', $fast_path, $fm)) {
        // GET /@{account_name} ユーザページ。存在時のみ高速描画、未存在(404)は Slim へフォールスルー。
        $helper = $container->get('helper');
        $account_name = rawurldecode($fm[1]);
        $user = $helper->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $account_name);
        if ($user !== false) {
            $db = $helper->db();
            $ps = $db->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC');
            $ps->execute([$user['id']]);
            $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
            $post_list_html = build_list_html($helper, $rows);

            $comment_count = $helper->fetch_first('SELECT COUNT(*) AS count FROM `comments` WHERE `user_id` = ?', $user['id'])['count'];

            $ps = $db->prepare('SELECT `id` FROM `posts` WHERE `user_id` = ?');
            $ps->execute([$user['id']]);
            $post_ids = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'id');
            $post_count = count($post_ids);

            $commented_count = 0;
            if ($post_count > 0) {
                $ph = implode(',', array_fill(0, count($post_ids), '?'));
                $commented_count = $helper->fetch_first("SELECT COUNT(*) AS count FROM `comments` WHERE `post_id` IN ({$ph})", ...$post_ids)['count'];
            }

            $me = $helper->get_session_user();
            $view = 'user.php';
            require __DIR__ . '/views/layout.php';
            exit;
        }
        // ユーザ未存在は Slim 経路へ（404 共通化）
    } elseif (preg_match('#^/image/(\d+)\.(\w+)$#', $fast_path, $fm)) {
        // GET /image フォールバック。通常画像はnginxが直配信するため、ここに来るのはファイル未存在(=404)が大半。
        // id==0 の特殊応答(空200)は元実装に委ねるため Slim へフォールスルー。
        $iid = (int)$fm[1];
        if ($iid !== 0) {
            $ext = $fm[2];
            $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'][$ext] ?? '';
            $path = dirname(__DIR__) . "/public/image/{$iid}.{$ext}";
            if ($mime !== '' && is_file($path)) {
                header("Content-Type: {$mime}");
                readfile($path);
                exit;
            }
            http_response_code(404);
            echo '404';
            exit;
        }
        // id==0 は Slim 経路へ
    }
}

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

// banされたユーザID集合(del_flg=1, 初期~2%=約50件)を APCu キャッシュ。
// 変化するのは /initialize(db_initialize) と POST /admin/banned のみ＝ほぼ不変。registerはdel_flg=0なので無関係。
// これでフィード選別の「毎回の SELECT users IN」を排除（ホットパスから同期DB往復を除去）。
function banned_user_ids($helper) {
    $b = apcu_fetch('banned_uids');
    if (!is_array($b)) {
        $ids = $helper->db()->query('SELECT `id` FROM `users` WHERE `del_flg` = 1')->fetchAll(PDO::FETCH_COLUMN);
        $b = [];
        foreach ($ids as $id) {
            $b[(int)$id] = true;
        }
        apcu_store('banned_uids', $b, 0); // TTL無し。ban/initializeで明示invalidate
    }
    return $b;
}

// 1投稿分の post.php をHTML文字列にレンダリング（csrfはプレースホルダ）。
function render_one_post_fragment(array $post) {
    $csrf_token = CSRF_PLACEHOLDER; // post.php が参照する
    ob_start();
    require __DIR__ . '/views/post.php';
    return ob_get_clean();
}

// 投稿リストのHTMLを断片キャッシュで組み立てる。
// $rows: posts の生行（id,user_id,body,mime,created_at,comment_count を含む。commentsは未取得でよい）。
// 断片キーは (post_id, comment_count)。comment_count(非正規化列)はクエリで取得済なので、
// **断片がヒットすればコメントを一切fetchしない**。コメント追加で comment_count が変わり自動invalidate。
// csrf はプレースホルダで描画し最後に実トークンへ合成（断片を全ユーザで共有）。
// $rows は軽量行（id, user_id, comment_count のみで可。body/mime/created_at はミス時にだけ取得）。
// これにより断片ヒット率の高い通常時、フィードは index-only クエリ(idx_feed)だけで完結し
// 40件ぶんの TEXT body を毎回 materialize する無駄を撲滅する。
function build_list_html($helper, array $rows) {
    // 断片キャッシュは APCu(プロセス共有メモリ)。memcachedのTCP往復を排し getは数µs。単一サーバなので一貫性問題なし。
    // 選別はキャッシュ済みban集合で行い、毎回の SELECT users IN を排除する。
    $banned = banned_user_ids($helper);
    $selected = [];
    foreach ($rows as $p) {
        if (!isset($banned[$p['user_id']])) { // del_flg=1 でない著者の投稿のみ
            $selected[] = $p;
            if (count($selected) >= POSTS_PER_PAGE) {
                break;
            }
        }
    }
    if (!$selected) {
        return '';
    }

    // 断片キャッシュを comment_count キーで一括引き
    $keys = [];
    foreach ($selected as $p) {
        $keys[$p['id']] = 'pf:' . (int)$p['id'] . ':c' . (int)($p['comment_count'] ?? 0);
    }
    $found = apcu_fetch(array_values($keys));
    if (!is_array($found)) {
        $found = [];
    }

    // ヒットしなかった投稿だけ、本体(body/mime/created_at)＋コメント(最新3件)＋必要ユーザをまとめて取得。
    // 全ヒット時は posts本体/users/comments のDB往復が一切走らない（鍵クエリの index-only のみ）。
    $miss_ids = [];
    foreach ($selected as $p) {
        if (!isset($found[$keys[$p['id']]])) {
            $miss_ids[] = $p['id'];
        }
    }
    $user_cache = [];
    $comments_by_post = [];
    $full_by_id = [];
    if ($miss_ids) {
        $ph = implode(',', array_fill(0, count($miss_ids), '?'));
        // 本体（断片描画に必要なフルカラム）
        $ps = $helper->db()->prepare("SELECT `id`,`user_id`,`body`,`mime`,`created_at`,`comment_count` FROM `posts` WHERE `id` IN ({$ph})");
        $ps->execute($miss_ids);
        foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $full_by_id[$r['id']] = $r;
        }
        // コメント
        $ps = $helper->db()->prepare("SELECT `id`,`post_id`,`user_id`,`comment`,`created_at` FROM `comments` WHERE `post_id` IN ({$ph}) ORDER BY `created_at` DESC");
        $ps->execute($miss_ids);
        $all = $ps->fetchAll(PDO::FETCH_ASSOC);
        // 必要ユーザ = ミス投稿の著者 + そのコメント主
        $uids = [];
        foreach ($full_by_id as $r) {
            $uids[] = $r['user_id'];
        }
        foreach ($all as $c) {
            $uids[] = $c['user_id'];
        }
        $helper->preload_users($uids, $user_cache);
        foreach ($all as $c) {
            $pid = $c['post_id'];
            if (count($comments_by_post[$pid] ?? []) < 3) {
                $c['user'] = $user_cache[$c['user_id']] ?? null;
                $comments_by_post[$pid][] = $c;
            }
        }
    }

    $html = '';
    $to_set = [];
    foreach ($selected as $p) {
        $k = $keys[$p['id']];
        if (isset($found[$k])) {
            $html .= $found[$k];
            continue;
        }
        $post = $full_by_id[$p['id']]; // ミス時に取得したフル行
        $post['user'] = $user_cache[$post['user_id']] ?? null;
        $post['comments'] = array_reverse($comments_by_post[$p['id']] ?? []);
        $frag = render_one_post_fragment($post);
        $to_set[$k] = $frag;
        $html .= $frag;
    }
    if ($to_set) {
        apcu_store($to_set, null, 600);
    }
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
    // DBリセットに伴い、古いキャッシュを全消去（initializeは最初に呼ばれ、保持すべきセッションは無い）。
    // 断片キャッシュは APCu、セッションは memcached なので両方クリア。
    apcu_clear_cache();
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
    // 書き込み経路。失敗時の flash も session に残す必要があるため先にセッション開始。
    ensure_session();
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
    // 書き込み経路。失敗時の flash も session に残す必要があるため先にセッション開始。
    ensure_session();
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
    ensure_session();
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

    // comment_count(非正規化列)込みで posts を取得 → build_list_html が断片ヒット時はコメントをfetchしない。
    // 新規投稿/コメントはこのクエリ結果(created_at順 / comment_count)に即反映されるので version不要・即時可視。
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` USE INDEX (idx_feed) ORDER BY `created_at` DESC LIMIT 40');
    $ps->execute();
    $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
    $list_html = build_list_html($this->get('helper'), $rows);

    return $this->get('view')->render($response, 'index.php', [
        'post_list_html' => $list_html,
        'me' => $me,
        'flash' => $this->get('flash')->getFirstMessage('notice'),
    ]);
});

$app->get('/posts', function (Request $request, Response $response) {
    $params = $request->getQueryParams();
    $max_created_at = $params['max_created_at'] ?? null;
    $db = $this->get('db');
    $ps = $db->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` USE INDEX (idx_feed) WHERE `created_at` <= ? ORDER BY `created_at` DESC LIMIT 40');
    $ps->execute([$max_created_at === null ? null : $max_created_at]);
    $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
    $list_html = build_list_html($this->get('helper'), $rows);

    return $this->get('view')->render($response, 'posts.php', ['post_list_html' => $list_html]);
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
        // 新規投稿は created_at 順クエリに即座に出現し、断片キー pf:{id}:c0 は未生成=即レンダリングされる
        // （feed_version 不要・即時可視）。
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

    // 非正規化列を増分更新。これにより断片キー pf:{id}:c{count} が変わり、当該投稿の断片が自動再生成される（即時可視）
    $up = $this->get('db')->prepare('UPDATE `posts` SET `comment_count` = `comment_count` + 1 WHERE `id` = ?');
    $up->execute([$post_id]);

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

    // ban集合キャッシュを無効化（次回フィード選別で再構築）
    apcu_delete('banned_uids');

    return redirect($response, '/admin/banned', 302);
});

$app->get('/@{account_name}', function (Request $request, Response $response, $args) {
    $db = $this->get('db');
    $user = $this->get('helper')->fetch_first('SELECT * FROM `users` WHERE `account_name` = ? AND `del_flg` = 0', $args['account_name']);

    if ($user === false) {
        $response->getBody()->write('404');
        return $response->withStatus(404);
    }

    $ps = $db->prepare('SELECT `id`, `user_id`, `comment_count` FROM `posts` WHERE `user_id` = ? ORDER BY `created_at` DESC');
    $ps->execute([$user['id']]);
    $rows = $ps->fetchAll(PDO::FETCH_ASSOC);
    $list_html = build_list_html($this->get('helper'), $rows);

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

    return $this->get('view')->render($response, 'user.php', ['post_list_html' => $list_html, 'user' => $user, 'post_count' => $post_count, 'comment_count' => $comment_count, 'commented_count'=> $commented_count, 'me' => $me]);
});

$app->run();
