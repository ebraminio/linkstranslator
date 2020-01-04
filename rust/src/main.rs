use {
    hyper::{
        self,
        service::{make_service_fn, service_fn},
        Body, Request, Response, Server, StatusCode,
    },
    serde_json::{self, json, Value},
    std::collections::HashMap,
    std::net::SocketAddr,
};

async fn serve_req(req: Request<Body>) -> Result<Response<Body>, hyper::Error> {
    let params: HashMap<String, String> = req
        .uri()
        .query()
        .map(|v| {
            url::form_urlencoded::parse(v.as_bytes())
                .into_owned()
                .collect()
        })
        .unwrap_or_else(HashMap::new);

    let from_default = "enwiki".to_string();
    let from = params.get("from").unwrap_or(&from_default);
    let to_default = "fawiki".to_string();
    let to = params.get("to").unwrap_or(&to_default);
    let titles = match params.get("p") {
        Some(s) => s,
        None => {
            return Ok(Response::builder()
                .header("Content-Type", "text/plain")
                .status(StatusCode::OK)
                .body(Body::from("A service to translate links based on Wikipedia \
language links, use it like: \
?p=Earth|Moon|Human|Water&from=enwiki&to=dewiki Source: github.com/ebraminio/linkstranslator"))
                .unwrap());
        }
    };

    let requests: Vec<&str> = titles.split("|").collect();
    let chunks_results = futures::future::join_all(requests.chunks(50).map(|chunk| {
        async move {
            let client = reqwest::Client::new();
            let res = client
                .post("https://www.wikidata.org/w/api.php")
                .form(&[
                    ("action", "wbgetentities"),
                    ("format", "json"),
                    ("sites", from),
                    ("titles", chunk.join("|").as_str()),
                    ("props", "sitelinks"),
                ])
                .send()
                .await
                .unwrap()
                .text()
                .await
                .unwrap();

            let c: Value = serde_json::from_str(res.as_str()).unwrap();
            let mut agg: HashMap<String, String> = HashMap::new();
            for (_, item) in c["entities"].as_object().unwrap().into_iter() {
                let mut from_title: Option<&str> = None;
                let mut to_title: Option<&str> = None;

                match item["sitelinks"].as_object() {
                    Some(t) => {
                        for (_, link) in t.into_iter() {
                            match link["site"].as_str() {
                                Some(site) => {
                                    if site == from {
                                        from_title = link["title"].as_str()
                                    } else if site == to {
                                        to_title = link["title"].as_str();
                                    }
                                }
                                None => {}
                            }
                        }
                    }
                    _ => {}
                }

                match (from_title, to_title) {
                    (Some(f), Some(t)) => {
                        agg.insert(f.to_string(), t.to_string());
                    },
                    _ => {}
                }
            }

            agg
        }
    })).await;

    let mut aggregation: HashMap<String, String> = HashMap::new();
    for x in chunks_results {
        aggregation.extend(x);
    }

    Ok(Response::builder()
        .header("Content-Type", "application/json")
        .status(StatusCode::OK)
        .body(Body::from(serde_json::to_string(&json!(aggregation)).unwrap()))
        .unwrap())
}

#[tokio::main]
async fn main() {
    let addr = SocketAddr::from(([127, 0, 0, 1], 3000));
    println!(
        "Listening on http://{}/?p=Earth|Moon|Human|Water&from=enwiki&to=dewiki",
        addr
    );

    if let Err(e) = Server::bind(&addr)
        .serve(make_service_fn(|_| {
            async { Ok::<_, hyper::Error>(service_fn(serve_req)) }
        }))
        .await
    {
        eprintln!("server error: {}", e);
    }
}
