function sparkline(elemId, data) {
    data = d3.csv.parse(data);
    var width = 100;
    var height = 25;
    var x = d3.scale.linear().range([0, width - 2]);
    var y = d3.scale.linear().range([height - 4, 0]);
    var parseDate = d3.time.format("%Y-%m-%d").parse;
    var line = d3.svg.line()
        .interpolate("linear")
        .x(function(d) { return x(d.date); })
        .y(function(d) { return y(d.close); });

    data.forEach(function(d) {
        d.date = parseDate(d.Date);
        d.close = +d.Close;
    });
    x.domain(d3.extent(data, function(d) { return d.date; }));
    y.domain(d3.extent(data, function(d) { return d.close; }));
    var svg = d3.select(elemId)
        .append('svg')
        .attr('width', width)
        .attr('height', height)
        .append('g')
        .attr('transform', 'translate(0, 2)');
    svg.append('path')
        .datum(data)
        .attr('class', 'sparkline')
        .attr('d', line);
    svg.append('circle')
        .attr('class', 'sparkcircle')
        .attr('cx', x(data[data.length - 1].date))
        .attr('cy', y(data[data.length - 1].close))
        .attr('r', 2);
}
