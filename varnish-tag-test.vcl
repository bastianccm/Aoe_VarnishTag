# this is a test-varnish-vcl to demonstrate tag-based cache clearing
# not meant to be used in production!
# based on https://groups.drupal.org/node/297773 and the varnish 3.0 manual

backend default {
    .host = "127.0.0.1";
    .port = "80";
}

# just some general always-caching config
sub vcl_fetch {
    set beresp.ttl = 1d;
    return (deliver);
}

sub vcl_recv {
# a PURGE request will invalidate all objects with a given tag
# there is no ACL or other security mechanisms, it's just for testing!!!
    if (req.request == "PURGE") {
        ban("obj.http.X-Invalidated-By ~ " + req.http.X-Invalidates);
        error 200 "Purged.";
    }

# default varnish vcl_recv source
     if (req.restarts == 0) {
            if (req.http.x-forwarded-for) {
                set req.http.X-Forwarded-For =
                req.http.X-Forwarded-For + ", " + client.ip;
            } else {
                set req.http.X-Forwarded-For = client.ip;
            }
     }
     if (req.request != "GET" &&
       req.request != "HEAD" &&
       req.request != "PUT" &&
       req.request != "POST" &&
       req.request != "TRACE" &&
       req.request != "OPTIONS" &&
       req.request != "DELETE") {
         /* Non-RFC2616 or CONNECT which is weird. */
         return (pipe);
     }
     if (req.request != "GET" && req.request != "HEAD") {
         /* We only deal with GET and HEAD by default */
         return (pass);
     }
     return (lookup);
 }
